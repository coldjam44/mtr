<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewBidEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $auctionId;
    public $bidAmount;
    public $bidderId;
    public $bidderName;
    public $timestamp;

    public function __construct($auctionId, $bidAmount, $bidderId, $bidderName)
    {
        $this->auctionId = $auctionId;
        $this->bidAmount = $bidAmount;
        $this->bidderId = $bidderId;
        $this->bidderName = $bidderName;
        $this->timestamp = now()->format('Y-m-d H:i:s');
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('auction.' . $this->auctionId),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'new.bid';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'auction_id' => $this->auctionId,
            'bid_amount' => $this->bidAmount,
            'bidder_id' => $this->bidderId,
            'bidder_name' => $this->bidderName,
            'timestamp' => $this->timestamp,
        ];
    }
}