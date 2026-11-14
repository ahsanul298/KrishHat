<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Offer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OfferController extends Controller
{
    /** GET /api/v1/offers — buyer: offers made, farmer: offers received. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Offer::query()->with('product:id,name,unit')->orderByDesc('id');

        if ($user->role === 'buyer') {
            $query->where('buyer_id', $user->id);
        } elseif ($user->role === 'farmer') {
            $query->where('farmer_id', $user->id);
        }

        $page = $query->paginate(15);

        $page->getCollection()->transform(fn ($o) => [
            'id' => $o->id,
            'product' => $o->product ? [
                'id' => $o->product->id,
                'name' => $o->product->name,
                'unit' => $o->product->unit,
            ] : null,
            'initial_price' => (float) $o->initial_price,
            'current_price' => (float) $o->current_price,
            'quantity' => (int) $o->quantity,
            'status' => $o->status,
            'created_at' => optional($o->created_at)->toIso8601String(),
        ]);

        return response()->json($page);
    }
}
