<?php

namespace App\Http\Controllers\Admin\Auction;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Country;
use App\Models\City;
use App\Models\CarModel;
use App\Models\CategoryField;
use App\Models\CategoryFieldValue;
use App\Models\Ad;
use App\Models\AuctionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class AuctionController extends Controller
{
    /**
     * Display the create auction form
     */
    public function create()
    {
        // Get all categories for dropdown
        $categories = Category::all();
        
        // Get all countries for dropdown
        $countries = Country::all();
        
        // Start with empty cities (will be populated via AJAX)
        $cities = collect([]);        // Get car makes from CategoryFieldValue (field 41 = Make field for cars)
        $carModels = CategoryFieldValue::where('category_field_id', 41)->get();
        
        // Get category fields for cars (assuming category_id = 1 is cars)
        $categoryFields = CategoryField::where('category_id', 1)->with('values')->get();
        
        return view('admin.pages.auctions.create', compact(
            'categories', 
            'countries', 
            'cities', 
            'carModels', 
            'categoryFields'
        ));
    }

    /**
     * Store a new auction by calling the Ad API controller
     */
    public function store(Request $request)
    {
        // Debug: Log that the method is called
        \Log::info('Auction store method called', ['request_data' => $request->all()]);
        
        try {
            // Validate the request
            $validatedData = $request->validate([
                'category_id' => 'required|exists:categories,id',
                'country_id' => 'required|exists:countries,id',
                'city_id' => 'required|exists:cities,id',
                'title' => 'required|string|max:255',
                'description' => 'required|string',
                'price' => 'required|numeric|min:0',
                'phone_number' => 'required|string',
                'kilometer' => 'required|numeric|min:0',
                'address' => 'required|string',
                'car_model' => 'required|exists:car_models,id',
                'starting_price' => 'required|numeric|min:0',
                'reserve_price' => 'nullable|numeric|min:0',
                'start_time' => 'required|date|after:now',
                'end_time' => 'required|date|after:start_time',
                'bid_increment' => 'required|numeric|min:1',
                'auto_extend' => 'boolean',
                'extend_minutes' => 'nullable|numeric|min:1',
                'main_image' => 'required|image|mimes:jpeg,png,jpg,gif',
                'sub_images.*' => 'image|mimes:jpeg,png,jpg,gif',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::error('Validation failed in auction store', ['errors' => $e->errors()]);
            
            return redirect()->back()
                ->withErrors($e->errors())
                ->withInput()
                ->with('error', __('messages.please_fix_validation_errors'));
        }

        try {
            // Step 1: Create HTTP client with token for AdController
            $adController = new \App\Http\Controllers\Api\AdController();
            
            // Add Authorization header if token is provided
            if ($request->has('api_token') && !empty($request->api_token)) {
                $request->headers->set('Authorization', 'Bearer ' . $request->api_token);
            }
            
            // Step 2: Call AdController store method
            $adResponse = $adController->store($request);
            
            // Step 3: Extract ad ID from response
            $adData = $adResponse->getData(true);
            
            if (!isset($adData['ad']['id'])) {
                return redirect()->back()
                    ->withInput()
                    ->with('error', __('messages.failed_to_create_ad') . ': ' . json_encode($adData));
            }
            
            $adId = $adData['ad']['id'];
            
            // Step 3: Update ad status to 'auction'
            $ad = Ad::find($adId);
            if ($ad) {
                $ad->status = 'auction';
                $ad->save();
            }
            
            // Step 4: Create auction data
            $startTime = Carbon::parse($request->start_time);
            $endTime = Carbon::parse($request->end_time);
            $durationHours = $startTime->diffInHours($endTime);
            
            $auction = AuctionHandler::create([
                'ad_id' => $adId,
                'starting_price' => $request->starting_price,
                'mini_bid_increment' => $request->bid_increment ?? 10,
                'auction_duration_hours' => $durationHours,
                'current_highest_bid' => $request->starting_price,
                'start_time' => $request->start_time,
                'end_time' => $request->end_time,
                'status' => 'pending', // Pending admin approval
            ]);

            return redirect()->route('admin.auctions.create')
                ->with('success', __('messages.auction_created_successfully'));
                
        } catch (\Exception $e) {
            // If auction creation fails, delete the ad if it was created
            if (isset($adId) && $adId) {
                $ad = Ad::find($adId);
                if ($ad) {
                    $ad->delete();
                }
            }
            
            return redirect()->back()
                ->withInput()
                ->with('error', __('messages.auction_creation_error') . ': ' . $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine());
        }
    }

    /**
     * Get cities for a specific country (AJAX)
     */
    public function getCitiesByCountry($countryId)
    {
        $cities = city::where('country_id', $countryId)->get(['id', 'name_ar', 'name_en']);
        return response()->json($cities);
    }

    /**
     * Get field values for a specific field (AJAX)
     */
    public function getFieldValues($fieldId)
    {
        $fieldValues = CategoryFieldValue::where('category_field_id', $fieldId)
            ->whereNull('field_type') // Get only final values, not references
            ->get();
        return response()->json($fieldValues);
    }

    /**
     * Get car models/makes for a specific category (AJAX)
     */
    public function getCarModelsByCategory($categoryId)
    {
        // For cars category (ID = 1), get makes from field 41
        if ($categoryId == 1) {
            $carModels = CategoryFieldValue::where('category_field_id', 41)->get(['id', 'value_ar', 'value_en']);
        } else {
            // For other categories, you might need different logic
            $carModels = collect([]);
        }
        
        return response()->json($carModels);
    }
}