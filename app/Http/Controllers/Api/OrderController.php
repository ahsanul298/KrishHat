<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    /** GET /api/v1/orders — buyer: own orders, farmer: orders containing own products, admin: all. */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Order::query()->with(['items.product:id,name'])->orderByDesc('id');

        if ($user->role === 'buyer') {
            $query->where('buyer_id', $user->id);
        } elseif ($user->role === 'farmer') {
            $query->whereHas('items', fn ($q) => $q->where('farmer_id', $user->id));
        }

        return OrderResource::collection($query->paginate(15));
    }

    /** GET /api/v1/orders/{order} */
    public function show(Request $request, Order $order): OrderResource
    {
        $user = $request->user();

        $allowed = match ($user->role) {
            'admin' => true,
            'buyer' => $order->buyer_id === $user->id,
            'farmer' => $order->items()->where('farmer_id', $user->id)->exists(),
            default => false,
        };

        abort_unless($allowed, 403, 'এই অর্ডারটি দেখার অনুমতি আপনার নেই।');

        return new OrderResource($order->load(['items.product:id,name', 'trackingEvents']));
    }
}
