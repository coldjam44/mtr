<?php

namespace App\Http\Controllers\Api;

use App\Models\Ad;
use App\Models\AuctionHandler;
use App\Models\AuctionBid;
use App\Models\Notification;
use App\Models\Userauth;
use App\Models\Category;
use App\Models\Country;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use Illuminate\Support\Str;
use App\Events\NewBidEvent;

class AuctionController extends Controller
{
    /**
     * إنشاء مزاد جديد
     * يستخدم AdController::store() أولاً ثم يضيف بيانات المزاد
     */
    public function createAuction(Request $request)
    {
        // التحقق من صحة بيانات المزاد
        $validator = Validator::make($request->all(), [
            'starting_price' => 'required|numeric|min:1',
            'auction_duration_days' => 'required|integer|min:1|max:365',
            'bid_increment' => 'nullable|numeric|min:1',
            // جميع حقول الإعلان العادي أيضاً مطلوبة
            'category_id' => 'required|exists:categories,id',
            'country_id' => 'required|exists:countries,id',
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'address' => 'required|string',
            'price' => 'required|numeric',
            'main_image' => 'required|image',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // التحقق من وجود الصورة الرئيسية
        if (!$request->hasFile('main_image')) {
            return response()->json([
                'error' => 'الصورة الرئيسية مطلوبة',
                'message' => 'يجب رفع صورة رئيسية للإعلان'
            ], 422);
        }

        // الخطوة 1: إنشاء الإعلان باستخدام AdController
        $adController = new \App\Http\Controllers\Api\AdController();
        
        // استدعاء دالة store من AdController مع نفس الـ request
        $adResponse = $adController->store($request);
        
        // الخطوة 2: استخراج معرف الإعلان من الاستجابة باستخدام الطريقة المقترحة
        $adData = $adResponse->getData(true);
        
        // التحقق من وجود بيانات الإعلان
        if (!isset($adData['ad']['id'])) {
            return response()->json([
                'error' => 'فشل في إنشاء الإعلان',
                'ad_response' => $adData
            ], 500);
        }
        
        $adId = $adData['ad']['id'];
        
        // الخطوة 3: تحديث حالة الإعلان إلى "auction"
        $ad = Ad::find($adId);
        if ($ad) {
            $ad->status = 'auction';
            $ad->save();
        }
        
        // الخطوة 4: إنشاء بيانات المزاد
        try {
            // تحويل مدة المزاد من أيام إلى ساعات
            $durationDays = $request->auction_duration_days;
            $durationHours = $durationDays * 24;
            
            // توليد رقم لوت فريد بصيغة Lot#XXXXXX
            $generatedLot = $this->generateLotNumber();

            $auction = AuctionHandler::create([
                'ad_id' => $adId,
                'lot_number' => $generatedLot,
                'starting_price' => $request->starting_price,
                'mini_bid_increment' => $request->bid_increment ?? 10,
                'auction_duration_hours' => $durationHours,
                'current_highest_bid' => $request->starting_price,
                'start_time' => null, // سيتم تحديدها عند الموافقة
                'end_time' => null, // سيتم تحديدها عند الموافقة
                'status' => 'pending', // في انتظار الموافقة
            ]);

            // تحديث الإشعارات لتصبح بصيغة المزاد وإضافة إشعارات الأدمن
            $this->updateNotificationsToAuctionFormat($ad, $auction);

            // Fetch complete ad details with all relationships
            $completeAd = Ad::with([
                'user',
                'category', 
                'country',
                'city',
                'images',
                'fieldValues.field',
                'fieldValues.fieldValue', 
                'features.value',
                'carModel',
                'reel'
            ])->find($adId);

            // Add image URLs to main and sub images
            if ($completeAd) {
                $completeAd->main_image_url = $completeAd->main_image ? url($completeAd->main_image) : null;
                
                // Add URLs to sub images
                $completeAd->sub_images_urls = $completeAd->images->map(function($image) {
                    return [
                        'id' => $image->id,
                        'image' => $image->image,
                        'url' => url($image->image)
                    ];
                });

                // Process field values the same way as AdController
                $completeAd->processed_field_values = $completeAd->fieldValues->transform(function ($fieldValue) {
                    $field = optional($fieldValue->field);
                    $fieldValueModel = optional($fieldValue->fieldValue);

                    $fieldType = $fieldValueModel->field_type ?? 'Unknown';
                    $valueAr = $fieldValueModel->value_ar ?? 'غير معروف';
                    $valueEn = $fieldValueModel->value_en ?? 'Unknown';

                    if ($fieldType === 'text') {
                        $currentValueId = $valueAr;
                        $maxDepth = 10; // لتجنب الحلقات اللامتناهية
                        $depth = 0;

                        while (is_numeric($currentValueId) && $depth < $maxDepth) {
                            $realValue = \App\Models\CategoryFieldValue::find($currentValueId);

                            if (!$realValue) {
                                // القيمة غير موجودة - نعرض رسالة خطأ بدلاً من ID
                                $valueAr = "قيمة مفقودة (ID: {$currentValueId})";
                                $valueEn = "Missing value (ID: {$currentValueId})";
                                break;
                            }

                            if ($realValue->category_field_id != $fieldValue->category_field_id) {
                                break; // الحقل مختلف - نتوقف
                            }

                            $valueAr = $realValue->value_ar ?? $valueAr;
                            $valueEn = $realValue->value_en ?? $valueEn;

                            if (!is_numeric($valueAr)) {
                                break; // وجدنا النص الحقيقي
                            }

                            $currentValueId = $valueAr; // نكمل البحث
                            $depth++;
                        }
                    }

                    return [
                        'field_id' => $fieldValue->category_field_id,
                        'field_name' => [
                            'ar' => $field->field_ar ?? 'غير معروف',
                            'en' => $field->field_en ?? 'Unknown',
                        ],
                        'field_value_id' => $fieldValue->category_field_value_id,
                        'field_value' => [
                            'ar' => $valueAr,
                            'en' => $valueEn,
                        ],
                        'field_type' => $fieldType,
                    ];
                });
            }

            return response()->json([
                'message' => 'تم إنشاء المزاد بنجاح',
                'ad_id' => $adId,
                'auction_id' => $auction->id,
                'ad_data' => [
                    'id' => $completeAd->id,
                    'title' => $completeAd->title,
                    'description' => $completeAd->description,
                    'price' => $completeAd->price,
                    'main_image_url' => $completeAd->main_image_url,
                    'sub_images_urls' => $completeAd->sub_images_urls,
                    'processed_field_values' => $completeAd->processed_field_values,
                    'user' => $completeAd->user,
                    'category' => $completeAd->category,
                    'country' => $completeAd->country,
                    'city' => $completeAd->city
                ],
                'auction_data' => $auction
            ], 201);

        } catch (\Exception $e) {
            // في حالة فشل إنشاء المزاد، نحذف الإعلان
            if ($ad) {
                $ad->delete();
            }
            
            return response()->json([
                'error' => 'فشل في إنشاء بيانات المزاد',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * عرض تفاصيل مزاد
     */
    public function show($auctionId)
    {
        $auction = AuctionHandler::with([
            'ad.user',
            'ad.category', 
            'ad.country',
            'ad.city',
            'ad.images',
            'ad.fieldValues.field',
            'ad.fieldValues.fieldValue', 
            'ad.features.value',
            'ad.carModel',
            'ad.reel',
            'bids.user'
        ])->find($auctionId);

        if (!$auction) {
            return response()->json(['message' => 'المزاد غير موجود'], 404);
        }

        // Add image URLs to ad data
        if ($auction->ad) {
            $auction->ad->main_image_url = $auction->ad->main_image ? url($auction->ad->main_image) : null;
            
            // Add URLs to sub images
            $auction->ad->sub_images_urls = $auction->ad->images->map(function($image) {
                return [
                    'id' => $image->id,
                    'image' => $image->image,
                    'url' => url($image->image)
                ];
            });

            // Process field values the same way as AdController
            $auction->ad->processed_field_values = $auction->ad->fieldValues->transform(function ($fieldValue) {
                $field = optional($fieldValue->field);
                $fieldValueModel = optional($fieldValue->fieldValue);

                $fieldType = $fieldValueModel->field_type ?? 'Unknown';
                $valueAr = $fieldValueModel->value_ar ?? 'غير معروف';
                $valueEn = $fieldValueModel->value_en ?? 'Unknown';

                if ($fieldType === 'text') {
                    $currentValueId = $valueAr;
                    $maxDepth = 10;
                    $depth = 0;

                    while (is_numeric($currentValueId) && $depth < $maxDepth) {
                        $realValue = \App\Models\CategoryFieldValue::find($currentValueId);

                        if (!$realValue) {
                            // القيمة غير موجودة - نعرض رسالة خطأ بدلاً من ID
                            $valueAr = "قيمة مفقودة (ID: {$currentValueId})";
                            $valueEn = "Missing value (ID: {$currentValueId})";
                            break;
                        }

                        if ($realValue->category_field_id != $fieldValue->category_field_id) {
                            break; // الحقل مختلف - نتوقف
                        }

                        $valueAr = $realValue->value_ar ?? $valueAr;
                        $valueEn = $realValue->value_en ?? $valueEn;

                        if (!is_numeric($valueAr)) {
                            break;
                        }

                        $currentValueId = $valueAr;
                        $depth++;
                    }
                }

                return [
                    'field_id' => $fieldValue->category_field_id,
                    'field_name' => [
                        'ar' => $field->field_ar ?? 'غير معروف',
                        'en' => $field->field_en ?? 'Unknown',
                    ],
                    'field_value_id' => $fieldValue->category_field_value_id,
                    'field_value' => [
                        'ar' => $valueAr,
                        'en' => $valueEn,
                    ],
                    'field_type' => $fieldType,
                ];
            });
        }

        return response()->json([
            'auction_data' => $auction,
            'ad_data' => [
                'id' => $auction->ad->id,
                'title' => $auction->ad->title,
                'description' => $auction->ad->description,
                'price' => $auction->ad->price,
                'main_image_url' => $auction->ad->main_image_url,
                'sub_images_urls' => $auction->ad->sub_images_urls,
                'processed_field_values' => $auction->ad->processed_field_values,
                'user' => $auction->ad->user,
                'category' => $auction->ad->category,
                'country' => $auction->ad->country,
                'city' => $auction->ad->city
            ],
            'remaining_time' => $this->getRemainingTime($auction->end_time),
            'is_active' => $this->isAuctionActive($auction)
        ]);
    }

    /**
     * وضع مزايدة تلقائية بالقيمة الدنيا التالية (bid now)
     */
    public function bidNow(Request $request, $auctionId)
    {
        try {
            $auction = AuctionHandler::with('ad')->find($auctionId);
            if (!$auction) {
                return response()->json(['message' => 'المزاد غير موجود'], 404);
            }

            if (!$this->isAuctionActive($auction)) {
                return response()->json(['message' => 'المزاد غير نشط'], 400);
            }

            $userId = auth()->id();
            if (!$userId) {
                return response()->json(['message' => 'يجب تسجيل الدخول'], 401);
            }

            // منع صاحب الإعلان من المزايدة
            if ($auction->ad && $auction->ad->user_id == $userId) {
                return response()->json(['message' => 'لا يمكنك المزايدة على مزادك الخاص'], 400);
            }

            // حساب القيمة الدنيا التالية
            $nextMinimum = $auction->current_highest_bid + $auction->mini_bid_increment;

            // إنشاء المزايدة
            $bid = AuctionBid::create([
                'auction_handler_id' => $auction->id,
                'user_id' => $userId,
                'bid_amount' => $nextMinimum
            ]);

            // تحديث السعر الحالي
            $auction->current_highest_bid = $nextMinimum;
            $auction->save();

            $totalBids = AuctionBid::where('auction_handler_id', $auction->id)->count();

            return response()->json([
                'message' => 'تمت المزايدة بنجاح (Bid Now)',
                'bid' => $bid,
                'new_current_price' => $nextMinimum,
                'total_bids' => $totalBids,
                'next_minimum_bid' => $nextMinimum + $auction->mini_bid_increment
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'فشل في تنفيذ المزايدة',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * وضع مزايدة جديدة
     */
    public function placeBid(Request $request, $auctionId)
    {
        $validator = Validator::make($request->all(), [
            'bid_amount' => 'required|numeric|min:0'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $auction = AuctionHandler::find($auctionId);
        if (!$auction) {
            return response()->json(['message' => 'المزاد غير موجود'], 404);
        }

        // التحقق من أن المزاد نشط
        if (!$this->isAuctionActive($auction)) {
            return response()->json(['message' => 'المزاد غير نشط'], 400);
        }

        $bidAmount = $request->bid_amount;
        $userId = auth()->id();

        // التحقق من أن المستخدم ليس صاحب الإعلان
        if ($auction->ad->user_id == $userId) {
            return response()->json(['message' => 'لا يمكنك المزايدة على مزادك الخاص'], 400);
        }

        // حساب أقل مبلغ للمزايدة
        $increment = $auction->mini_bid_increment;
        $current = $auction->current_highest_bid;
        $minimumBid = $current + $increment;
        if ($bidAmount < $minimumBid) {
            return response()->json([
                'message' => "المبلغ يجب أن يكون على الأقل {$minimumBid}",
                'minimum_bid' => $minimumBid,
                'increment' => $increment
            ], 400);
        }

        // التحقق أن الفرق مضاعفات قيمة الزيادة (لا يقبل 101 أو 138 لو الزيادة 39 وكان الحالي 100)
        $difference = $bidAmount - $current;
        // لتحاشي مشاكل القيم العشرية نضرب *100 ونستخدم صحيح
        $diffInt = (int) round($difference * 100);
        $incInt = (int) round($increment * 100);
        if ($incInt > 0 && $diffInt % $incInt !== 0) {
            return response()->json([
                'message' => 'قيمة المزايدة يجب أن تكون بمضاعفات الزيادة المحددة',
                'current_highest_bid' => $current,
                'bid_amount_sent' => $bidAmount,
                'increment' => $increment,
                'allowed_example_next' => $minimumBid,
                'difference' => $difference
            ], 400);
        }

        try {
            // إنشاء المزايدة
            $bid = AuctionBid::create([
                'auction_handler_id' => $auctionId,
                'user_id' => $userId,
                'bid_amount' => $bidAmount
            ]);

            // تحديث السعر الحالي وعدد المزايدات
            // تحديث سعر المزاد الحالي
            $auction->current_highest_bid = $bidAmount;
            $auction->save();

            // إرسال حدث المزايدة الجديدة عبر Pusher
            event(new NewBidEvent($auctionId, $bidAmount, $userId, auth()->user()->first_name . ' ' . auth()->user()->last_name));
            
            // عد المزايدات
            $totalBids = AuctionBid::where('auction_handler_id', $auction->id)->count();
            
            return response()->json([
                'message' => 'تم وضع المزايدة بنجاح',
                'bid' => $bid,
                'new_current_price' => $bidAmount,
                'total_bids' => $totalBids
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'فشل في وضع المزايدة',
                'message' => $e->getMessage()
            ], 500);
        }
    }



    /**
     * عرض جميع المزادات النشطة
     */
    public function activeAuctions(Request $request)
    {
        // Slim listing: eager load only what we need (ad, user, bids) to reduce payload
        $query = AuctionHandler::with([
            'ad:id,title,description,main_image,user_id',
            'ad.user:id,first_name,last_name,profile_image',
            // no sub images for ongoing
            'bids:id,auction_handler_id' // for count only
        ])->select([
            'id','ad_id','lot_number','current_highest_bid','starting_price','start_time','end_time','status'
        ])->where('status', 'active')
            ->where('start_time', '<=', now())
            ->where('end_time', '>=', now());

        // فلترة حسب التصنيف
        if ($request->has('category_id')) {
            $query->whereHas('ad', function($q) use ($request) {
                $q->where('category_id', $request->category_id);
            });
        }

        // فلترة حسب الدولة
        if ($request->has('country_id')) {
            $query->whereHas('ad', function($q) use ($request) {
                $q->where('country_id', $request->country_id);
            });
        }

        $auctions = $query->orderBy('end_time', 'asc')->get()->map(function($auction) {
            $mainImageUrl = $auction->ad && $auction->ad->main_image ? url($auction->ad->main_image) : null;
            $bidsCount = $auction->bids ? $auction->bids->count() : 0;
            $user = $auction->ad?->user;
            $avatar = null;
            if ($user && $user->profile_image) {
                $avatar = url('profile_images/' . ltrim($user->profile_image, '/'));
            }
            return [
                'auction_id' => $auction->id,
                'lot_number' => $auction->lot_number,
                'title' => $auction->ad->title ?? null,
                'main_image' => $mainImageUrl,
                'remaining_time' => $this->getRemainingTime($auction->end_time),
                'bids_count' => $bidsCount,
                'current_price' => $auction->current_highest_bid,
                'seller_avatar' => $avatar
            ];
        });

        return response()->json([
            'data' => $auctions,
            'count' => $auctions->count()
        ]);
    }

    /**
     * Live auctions: same as active auctions list but includes sub_images field.
     */
    public function liveAuctions(Request $request)
    {
        $query = AuctionHandler::with([
            'ad:id,title,description,main_image,user_id',
            'ad.user:id,first_name,last_name,profile_image',
            'ad.images:id,ad_id,image',
            'bids:id,auction_handler_id'
        ])->select([
            'id','ad_id','lot_number','current_highest_bid','starting_price','start_time','end_time','status'
        ])->where('status', 'active')
          ->where('start_time', '<=', now())
          ->where('end_time', '>=', now());

        // Optional filters
        if ($request->has('category_id')) {
            $query->whereHas('ad', function($q) use ($request) {
                $q->where('category_id', $request->category_id);
            });
        }
        if ($request->has('country_id')) {
            $query->whereHas('ad', function($q) use ($request) {
                $q->where('country_id', $request->country_id);
            });
        }

        $auctions = $query->orderBy('end_time', 'asc')->get()->map(function($auction) {
            $mainImagePath = $auction->ad->main_image ?? null;
            $mainImageUrl = $mainImagePath ? url($mainImagePath) : null;
            $bidsCount = $auction->bids ? $auction->bids->count() : 0;
            $user = $auction->ad?->user;
            $avatar = $user && $user->profile_image ? url('profile_images/' . ltrim($user->profile_image, '/')) : null;
            $remaining = $this->getRemainingTime($auction->end_time);
            $remainingText = isset($remaining['expired']) && $remaining['expired'] ? 'Expired' : (isset($remaining['days']) ? sprintf('%dd :%02dh :%02dm', $remaining['days'], $remaining['hours'], $remaining['minutes']) : null);
            // Build sub images
            $subImages = [];
            if ($auction->ad && $auction->ad->images) {
                $subImages = $auction->ad->images->filter(function($img) use ($mainImagePath) {
                    return $img->image !== $mainImagePath; // exclude main
                })->map(function($img) {
                    return url($img->image);
                })->values()->all();
            }
            return [
                'auction_id' => $auction->id,
                'lot_number' => $auction->lot_number,
                'title' => $auction->ad->title ?? null,
                'main_image' => $mainImageUrl,
                'sub_images' => $subImages,
                'remaining_time' => $remaining,
                'remaining_time_text' => $remainingText,
                'bids_count' => $bidsCount,
                'current_price' => $auction->current_highest_bid,
                'seller_avatar' => $avatar,
                'ending_soon' => ($remaining['expired'] === false && $remaining['total_seconds'] <= 3600)
            ];
        });

        return response()->json([
            'data' => $auctions,
            'count' => $auctions->count()
        ]);
    }

    /**
     * حساب الوقت المتبقي للمزاد
     */
    private function getRemainingTime($endTime)
    {
        $now = Carbon::now();
        $end = Carbon::parse($endTime);
        
        if ($end->isPast()) {
            return ['expired' => true];
        }

        $diff = $now->diff($end);
        
        return [
            'expired' => false,
            'days' => $diff->days,
            'hours' => $diff->h,
            'minutes' => $diff->i,
            'seconds' => $diff->s,
            'total_seconds' => $now->diffInSeconds($end)
        ];
    }

    /**
     * التحقق من أن المزاد نشط
     */
    private function isAuctionActive($auction)
    {
        $now = Carbon::now();
        return $auction->status === 'active' && 
               $now->greaterThanOrEqualTo($auction->start_time) && 
               $now->lessThanOrEqualTo($auction->end_time);
    }

    /**
     * تفعيل مزاد (للأدمن فقط)
     */
    public function activateAuction(Request $request, $id)
    {
        try {
            // التحقق من المصادقة
            $user = JWTAuth::parseToken()->authenticate();
            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'غير مصرح لك بالوصول'
                ], 401);
            }

            // البحث عن المزاد
            $auction = AuctionHandler::with(['ad.user'])->find($id);
            if (!$auction) {
                return response()->json([
                    'status' => false,
                    'message' => 'المزاد غير موجود'
                ], 404);
            }

            // التحقق من حالة المزاد الحالية
            if ($auction->status !== 'pending') {
                return response()->json([
                    'status' => false,
                    'message' => 'لا يمكن تفعيل هذا المزاد. الحالة الحالية: ' . $auction->status,
                    'current_status' => $auction->status
                ], 400);
            }

            // حساب أوقات المزاد بناءً على وقت الموافقة والمدة المحددة
            $now = Carbon::now();
            $startTime = $now;
            $endTime = $now->copy()->addHours($auction->auction_duration_hours);

            // تحديث حالة المزاد مع الأوقات المحسوبة
            $auction->update([
                'status' => 'active',
                'start_time' => $startTime,
                'end_time' => $endTime
            ]);

            // تحديث حالة الإعلان المرتبط إذا لزم الأمر
            if ($auction->ad && $auction->ad->status === 'pending') {
                $auction->ad->update(['status' => 'approved']);
            }

            // إرجاع الاستجابة مع تفاصيل المزاد المحدثة
            $auction->refresh();
            
            return response()->json([
                'status' => true,
                'message' => 'تم تفعيل المزاد بنجاح',
                'auction' => [
                    'id' => $auction->id,
                    'ad_id' => $auction->ad_id,
                    'lot_number' => $auction->lot_number,
                    'status' => $auction->status,
                    'starting_price' => $auction->starting_price,
                    'current_highest_bid' => $auction->current_highest_bid,
                    'start_time' => $auction->start_time,
                    'end_time' => $auction->end_time,
                    'remaining_time' => $this->getRemainingTime($auction->end_time),
                    'is_active' => $this->isAuctionActive($auction)
                ]
            ], 200);

        } catch (\Tymon\JWTAuth\Exceptions\TokenExpiredException $e) {
            return response()->json([
                'status' => false,
                'message' => 'انتهت صلاحية التوكن'
            ], 401);
        } catch (\Tymon\JWTAuth\Exceptions\TokenInvalidException $e) {
            return response()->json([
                'status' => false,
                'message' => 'التوكن غير صالح'
            ], 401);
        } catch (\Tymon\JWTAuth\Exceptions\JWTException $e) {
            return response()->json([
                'status' => false,
                'message' => 'التوكن غير موجود'
            ], 401);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'حدث خطأ أثناء تفعيل المزاد: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * جلب جميع المزادات مع معلومات البطاقة الأساسية
     */
    public function getAllAuctions(Request $request)
    {
        $query = AuctionHandler::with([
            'ad.user',
            'ad.category', 
            'ad.country',
            'ad.city',
            'ad.images',
            'bids'
        ]);

        // فلترة حسب التصنيف
        if ($request->has('category_id')) {
            $query->whereHas('ad', function($q) use ($request) {
                $q->where('category_id', $request->category_id);
            });
        }

        // فلترة حسب الدولة
        if ($request->has('country_id')) {
            $query->whereHas('ad', function($q) use ($request) {
                $q->where('country_id', $request->country_id);
            });
        }

        // فلترة حسب الحالة
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $auctions = $query->orderBy('created_at', 'desc')->get();

        $auctions = $auctions->map(function($auction) {
            // تحديد حالة المزاد
            $now = Carbon::now();
            $isActive = $auction->status === 'active' && 
                       $now->greaterThanOrEqualTo($auction->start_time) && 
                       $now->lessThanOrEqualTo($auction->end_time);
            
            $isExpired = $now->greaterThan($auction->end_time);
            $isUpcoming = $now->lessThan($auction->start_time);

            // حساب الوقت المتبقي أو المنقضي
            $timeInfo = $this->getRemainingTime($auction->end_time);

            // معلومات البطاقة الأساسية
            return [
                'auction_id' => $auction->id,
                'lot_number' => $auction->lot_number,
                'ad_id' => $auction->ad_id,
                'title' => $auction->ad->title ?? 'عنوان غير متوفر',
                'description' => \Str::limit($auction->ad->description ?? '', 100),
                'main_image_url' => $auction->ad->main_image ? url($auction->ad->main_image) : null,
                'current_price' => $auction->current_highest_bid,
                'starting_price' => $auction->starting_price,
                'bid_increment' => $auction->mini_bid_increment,
                'total_bids' => $auction->bids->count(),
                'category' => [
                    'id' => $auction->ad->category->id ?? null,
                    'name_ar' => $auction->ad->category->name_ar ?? 'غير محدد',
                    'name_en' => $auction->ad->category->name_en ?? 'Not specified'
                ],
                'location' => [
                    'country' => $auction->ad->country->name_ar ?? 'غير محدد',
                    'city' => $auction->ad->city->name_ar ?? 'غير محدد'
                ],
                'seller' => [
                    'id' => $auction->ad->user->id ?? null,
                    'name' => trim(($auction->ad->user->first_name ?? '') . ' ' . ($auction->ad->user->last_name ?? '')) ?: 'غير معروف',
                    'profile_image' => $auction->ad->user->profile_image ? url('profile_images/' . $auction->ad->user->profile_image) : null
                ],
                'status' => [
                    'auction_status' => $auction->status,
                    'is_active' => $isActive,
                    'is_expired' => $isExpired,
                    'is_upcoming' => $isUpcoming
                ],
                'timing' => [
                    'start_time' => $auction->start_time,
                    'end_time' => $auction->end_time,
                    'remaining_time' => $timeInfo,
                    'created_at' => $auction->created_at
                ]
            ];
        });

        return response()->json([
            'success' => true,
            'total_count' => $auctions->count(),
            'auctions' => $auctions
        ]);
    }

    /**
     * عرض صفحة التفاصيل الكاملة للمزاد (Full Details Page)
     */
    public function fullDetailsPage($auctionId)
    {
        $auction = AuctionHandler::with([
            'ad.user',
            'ad.category', 
            'ad.country',
            'ad.city',
            'ad.images',
            'ad.fieldValues.field',
            'ad.fieldValues.fieldValue', 
            'ad.features.value',
            'ad.carModel',
            'ad.reel',
            'bids.user'
        ])->find($auctionId);

        if (!$auction) {
            return response()->json(['message' => 'المزاد غير موجود'], 404);
        }

        // Add image URLs to ad data
        if ($auction->ad) {
            $auction->ad->main_image_url = $auction->ad->main_image ? url($auction->ad->main_image) : null;
            
            // Add URLs to sub images
            $auction->ad->sub_images_urls = $auction->ad->images->map(function($image) {
                return [
                    'id' => $image->id,
                    'image' => $image->image,
                    'url' => url($image->image)
                ];
            });

            // Process field values the same way as AdController
            $auction->ad->processed_field_values = $auction->ad->fieldValues->transform(function ($fieldValue) {
                $field = optional($fieldValue->field);
                $fieldValueModel = optional($fieldValue->fieldValue);

                $fieldType = $fieldValueModel->field_type ?? 'Unknown';
                $valueAr = $fieldValueModel->value_ar ?? 'غير معروف';
                $valueEn = $fieldValueModel->value_en ?? 'Unknown';

                if ($fieldType === 'text') {
                    $currentValueId = $valueAr;
                    $maxDepth = 10;
                    $depth = 0;

                    while (is_numeric($currentValueId) && $depth < $maxDepth) {
                        $realValue = \App\Models\CategoryFieldValue::find($currentValueId);

                        if (!$realValue) {
                            $valueAr = "قيمة مفقودة (ID: {$currentValueId})";
                            $valueEn = "Missing value (ID: {$currentValueId})";
                            break;
                        }

                        if ($realValue->category_field_id != $fieldValue->category_field_id) {
                            break;
                        }

                        $valueAr = $realValue->value_ar ?? $valueAr;
                        $valueEn = $realValue->value_en ?? $valueEn;

                        if (!is_numeric($valueAr)) {
                            break;
                        }

                        $currentValueId = $valueAr;
                        $depth++;
                    }
                }

                return [
                    'field_id' => $fieldValue->category_field_id,
                    'field_name' => [
                        'ar' => $field->field_ar ?? 'غير معروف',
                        'en' => $field->field_en ?? 'Unknown',
                    ],
                    'field_value_id' => $fieldValue->category_field_value_id,
                    'field_value' => [
                        'ar' => $valueAr,
                        'en' => $valueEn,
                    ],
                    'field_type' => $fieldType,
                ];
            });
        }

        // Calculate remaining time and status
        $remainingTime = $this->getRemainingTime($auction->end_time);
        $isActive = $this->isAuctionActive($auction);
        
        // Get total bids count
        $totalBids = $auction->bids->count();
        
        // Get next minimum bid
        $nextMinimumBid = $auction->current_highest_bid + $auction->mini_bid_increment;

        return response()->json([
            'success' => true,
            'auction_id' => $auction->id,
            'lot_number' => $auction->lot_number,
            'page_data' => [
                // Auction info for page header
                'auction_info' => [
                    'id' => $auction->id,
                    'lot_number' => $auction->lot_number,
                    'title' => $auction->ad->title ?? 'عنوان غير متوفر',
                    'current_price' => $auction->current_highest_bid,
                    'starting_price' => $auction->starting_price,
                    'min_increment' => $auction->mini_bid_increment,
                    'next_minimum_bid' => $nextMinimumBid,
                    'total_bids' => $totalBids,
                    'remaining_time' => $remainingTime,
                    'is_active' => $isActive,
                    'status' => $auction->status,
                    'currency' => $auction->ad->country->currency_ar ?? 'درهم'
                ],
                
                // Images for gallery
                'images' => [
                    'main_image' => $auction->ad->main_image_url,
                    'sub_images' => $auction->ad->sub_images_urls
                ],
                
                // Vehicle specifications
                'specifications' => $auction->ad->processed_field_values,
                
                // Car features/options
                'features' => $auction->ad->features->map(function($feature) {
                    return [
                        'id' => $feature->id,
                        'feature_name' => [
                            'ar' => $feature->value->value_ar ?? 'غير معروف',
                            'en' => $feature->value->value_en ?? 'Unknown'
                        ],
                        'category' => $feature->value->field_type ?? 'car_option'
                    ];
                }),
                
                // Seller information
                'seller' => [
                    'id' => $auction->ad->user->id ?? null,
                    'name' => trim(($auction->ad->user->first_name ?? '') . ' ' . ($auction->ad->user->last_name ?? '')) ?: 'غير معروف',
                    'profile_image' => $auction->ad->user->profile_image ? url('profile_images/' . $auction->ad->user->profile_image) : null,
                    'phone' => $auction->ad->phone_number ?? null
                ],
                
                // Location information
                'location' => [
                    'address' => $auction->ad->address ?? 'غير محدد',
                    'city' => $auction->ad->city->name_ar ?? 'غير محدد',
                    'country' => $auction->ad->country->name_ar ?? 'غير محدد'
                ],
                
                // Bidding history
                'bidding_history' => $auction->bids->map(function($bid) use ($auction) {
                    return [
                        'id' => $bid->id,
                        'amount' => $bid->bid_amount,
                        'user_name' => trim(($bid->user->first_name ?? '') . ' ' . ($bid->user->last_name ?? '')) ?: 'مجهول',
                        'user_avatar' => $bid->user->profile_image ? url('profile_images/' . $bid->user->profile_image) : null,
                        'created_at' => $bid->created_at,
                        'is_highest' => $bid->bid_amount == $auction->current_highest_bid
                    ];
                })->sortByDesc('amount')->values(),
                
                // Additional details
                'additional_info' => [
                    'description' => $auction->ad->description ?? 'لا يوجد وصف',
                    'kilometers' => $auction->ad->kilometer ?? null,
                    'category' => [
                        'id' => $auction->ad->category->id ?? null,
                        'name_ar' => $auction->ad->category->name_ar ?? 'غير محدد',
                        'name_en' => $auction->ad->category->name_en ?? 'Not specified'
                    ]
                ]
            ]
        ]);
    }

    /**
     * جلب قائمة مزادات المستخدم المسجل دخوله
     */
    public function myAuctions(Request $request)
    {
        try {
            // الحصول على المستخدم من JWT token
            $user = JWTAuth::parseToken()->authenticate();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'المستخدم غير مصرح له'
                ], 401);
            }

            // جلب جميع المزادات الخاصة بالمستخدم
            $auctions = AuctionHandler::with([
                'ad' => function($query) {
                    $query->select('id', 'user_id', 'title', 'main_image', 'status', 'created_at');
                },
                'bids' => function($query) {
                    $query->select('auction_handler_id', 'bid_amount', 'created_at')
                          ->orderBy('bid_amount', 'desc');
                }
            ])
            ->whereHas('ad', function($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->orderBy('created_at', 'desc')
            ->get();

            // تنسيق البيانات للإرسال
            $formattedAuctions = $auctions->map(function ($auction) {
                // حساب الوقت المتبقي
                $now = Carbon::now();
                $endTime = Carbon::parse($auction->end_time);
                
                // حساب الوقت المتبقي
                $remainingTime = [];
                if ($endTime->isPast()) {
                    $remainingTime = [
                        'expired' => true,
                        'days' => 0,
                        'hours' => 0,
                        'minutes' => 0,
                        'seconds' => 0,
                        'total_seconds' => 0
                    ];
                } else {
                    $diff = $now->diff($endTime);
                    $totalSeconds = $endTime->diffInSeconds($now);
                    $remainingTime = [
                        'expired' => false,
                        'days' => $diff->days,
                        'hours' => $diff->h,
                        'minutes' => $diff->i,
                        'seconds' => $diff->s,
                        'total_seconds' => $totalSeconds
                    ];
                }

                // تحديد حالة المزاد
                $status = 'unknown';
                if ($auction->status === 'pending') {
                    $status = 'pending';
                } elseif ($auction->status === 'active' && $endTime->isPast()) {
                    $status = 'ended';
                } elseif ($auction->status === 'active') {
                    $status = 'active';
                } else {
                    $status = $auction->status;
                }

                return [
                    'auction_id' => $auction->id,
                    'lot_number' => $auction->lot_number,
                    'title' => $auction->ad->title ?? 'غير محدد',
                    'main_image' => $auction->ad->main_image ? 
                        url($auction->ad->main_image) : null,
                    'starting_price' => number_format((float)$auction->starting_price, 2),
                    'current_price' => number_format((float)$auction->current_highest_bid, 2),
                    'total_bids' => $auction->bids->count(),
                    'highest_bid' => $auction->bids->first() ? 
                        number_format((float)$auction->bids->first()->bid_amount, 2) : 
                        number_format((float)$auction->starting_price, 2),
                    'status' => $status,
                    'remaining_time' => $remainingTime,
                    'created_at' => $auction->created_at->format('Y-m-d H:i:s'),
                    'start_time' => $auction->start_time,
                    'end_time' => $auction->end_time,
                    'ad_status' => $auction->ad->status ?? 'unknown'
                ];
            });

            return response()->json([
                'success' => true,
                'user_id' => $user->id,
                'total_auctions' => $formattedAuctions->count(),
                'auctions' => $formattedAuctions
            ]);

        } catch (\Tymon\JWTAuth\Exceptions\TokenExpiredException $e) {
            return response()->json([
                'success' => false,
                'message' => 'انتهت صلاحية الرمز المميز'
            ], 401);
        } catch (\Tymon\JWTAuth\Exceptions\TokenInvalidException $e) {
            return response()->json([
                'success' => false,
                'message' => 'الرمز المميز غير صحيح'
            ], 401);
        } catch (\Tymon\JWTAuth\Exceptions\JWTException $e) {
            return response()->json([
                'success' => false,
                'message' => 'الرمز المميز غير موجود'
            ], 401);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطأ في الخادم',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * تحديث الإشعارات من صيغة إعلان إلى صيغة مزاد وإضافة إشعارات الأدمن
     */
    private function updateNotificationsToAuctionFormat($ad, $auction)
    {
        try {
            // الحصول على بيانات الفئة والدولة للإشعارات
            $category = Category::find($ad->category_id);
            $country = Country::find($ad->country_id);
            
            $categoryNameAr = $category->name_ar ?? 'غير محدد';
            $categoryNameEn = $category->name_en ?? 'Unknown';
            $countryNameAr = $country->name_ar ?? 'غير محدد';
            $countryNameEn = $country->name_en ?? 'Unknown';

            // 1. تحديث إشعار صاحب المزاد من "إعلان" إلى "مزاد"
            Notification::where('ad_id', $ad->id)
                ->where('type', 'ad_status')
                ->where('user_id', $ad->user_id)
                ->update([
                    'type' => 'auction_status',
                    'message_ar' => "مزادك رقم {$auction->lot_number} في قسم {$categoryNameAr} ب{$countryNameAr} قيد المراجعة!",
                    'message_en' => "Your auction {$auction->lot_number} in {$categoryNameEn} at {$countryNameEn} is under review!"
                ]);

            // 2. تحديث إشعارات المتابعين (إذا وجدت) من "إعلان جديد" إلى "مزاد جديد"
            Notification::where('ad_id', $ad->id)
                ->whereIn('type', ['new_ad', 'followed_user_ad_approved'])
                ->update([
                    'type' => 'new_auction',
                    'message_ar' => "المستخدم الذي تتابعه أنشأ مزاد جديد رقم {$auction->lot_number} في {$categoryNameAr} - {$countryNameAr}",
                    'message_en' => "A user you follow created a new auction {$auction->lot_number} in {$categoryNameEn} - {$countryNameEn}"
                ]);

            // 3. تحديث إشعارات الأدمن من "إعلان جديد" إلى "مزاد جديد"
            Notification::where('ad_id', $ad->id)
                ->whereIn('type', ['admin_new_ad', 'ad_review'])
                ->update([
                    'type' => 'admin_new_auction',
                    'message_ar' => "تم إنشاء مزاد جديد رقم {$auction->lot_number} بعنوان: {$ad->title} في قسم {$categoryNameAr} ب{$countryNameAr}",
                    'message_en' => "A new auction {$auction->lot_number} has been created titled: {$ad->title} in {$categoryNameEn} at {$countryNameEn}"
                ]);

            // 4. إضافة إشعار إضافي للأدمن عن مزاد قيد المراجعة
            $admins = Userauth::where('role', 'admin')->get();
            foreach ($admins as $admin) {
                // التحقق من عدم وجود إشعار مكرر
                $existingNotification = Notification::where('ad_id', $ad->id)
                    ->where('user_id', $admin->id)
                    ->where('type', 'auction_review')
                    ->exists();

                if (!$existingNotification) {
                    Notification::create([
                        'user_id' => $admin->id,
                        'from_user_id' => $ad->user_id,
                        'ad_id' => $ad->id,
                        'type' => 'auction_review',
                        'message_ar' => "يوجد مزاد جديد رقم {$auction->lot_number} في قسم '{$categoryNameAr}' من دولة '{$countryNameAr}' قيد المراجعة",
                        'message_en' => "A new auction {$auction->lot_number} in category '{$categoryNameEn}' from country '{$countryNameEn}' is pending review",
                        'is_read' => false,
                    ]);
                }
            }

        } catch (\Exception $e) {
            // في حالة فشل تحديث الإشعارات، نسجل الخطأ ولكن لا نوقف العملية
            \Log::error('Failed to update auction notifications: ' . $e->getMessage());
        }
    }

    /**
     * توليد رقم لوت فريد Lot#XXXXXX
     */
    private function generateLotNumber(): string
    {
        do {
            $number = 'Lot#' . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (AuctionHandler::where('lot_number', $number)->exists());
        return $number;
    }
}
