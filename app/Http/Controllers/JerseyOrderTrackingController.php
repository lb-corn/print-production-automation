<?php

namespace App\Http\Controllers;

use App\Enums\ShopifyFulfillmentStatus;
use App\Models\JerseyOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class JerseyOrderTrackingController extends Controller
{
    public function update(Request $request, JerseyOrder $jerseyOrder): RedirectResponse
    {
        $request->merge(['tracking_number'=>trim((string)$request->input('tracking_number'))]);
        $validated = $request->validate([
            'tracking_number' => ['required', 'string', 'max:255'],
            'return_to' => ['nullable', 'string', 'max:2000'],
        ], [
            'tracking_number.required' => 'Enter a tracking number before saving.',
        ]);

        abort_unless($request->user()?->allows('apparel.tracking.edit'), 403);

        if ($jerseyOrder->shopify_fulfillment_status === ShopifyFulfillmentStatus::Fulfilled->value || $jerseyOrder->shopify_cancelled_at !== null) {
            throw ValidationException::withMessages([
                'tracking_number' => $jerseyOrder->shopify_cancelled_at !== null
                    ? 'Cancelled orders cannot be edited.'
                    : 'Fulfilled orders cannot be edited.',
            ]);
        }

        $trackingNumber = trim((string) ($validated['tracking_number'] ?? ''));

        $jerseyOrder->forceFill([
            'tracking_number' => $trackingNumber === '' ? null : $trackingNumber,
            'tracking_updated_at' => now(),
            'tracking_updated_by' => Auth::id(),
        ])->save();

        return redirect()
            ->to($this->safeReturnTo($validated['return_to'] ?? null))
            ->with('ok', 'Tracking saved.');
    }

    private function safeReturnTo(?string $returnTo): string
    {
        if (is_string($returnTo) && ($returnTo === '/apparel/orders' || str_starts_with($returnTo, '/apparel/orders?'))) {
            return $returnTo;
        }

        return route('apparel.orders.index');
    }
}
