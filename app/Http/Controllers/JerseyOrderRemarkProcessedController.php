<?php

namespace App\Http\Controllers;

use App\Models\JerseyOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class JerseyOrderRemarkProcessedController extends Controller
{
    public function update(Request $request, JerseyOrder $jerseyOrder): RedirectResponse
    {
        abort_unless($request->user()?->allows('apparel.remarks.approve'), 403);

        $validated = $request->validate([
            'processed' => ['nullable', 'boolean'],
            'return_to' => ['nullable', 'string', 'max:2000'],
        ]);

        $processed = (bool) ($validated['processed'] ?? false);

        if ($processed && blank($jerseyOrder->remark)) {
            throw ValidationException::withMessages([
                'processed' => 'Add a remark before marking it processed.',
            ]);
        }

        $jerseyOrder->forceFill([
            'remark_processed_at' => $processed ? now() : null,
            'remark_processed_by' => $processed ? $request->user()->id : null,
        ])->save();

        return redirect()
            ->to($this->safeReturnTo($validated['return_to'] ?? null))
            ->with('ok', 'Remark status saved.');
    }

    private function safeReturnTo(?string $returnTo): string
    {
        if (is_string($returnTo) && ($returnTo === '/apparel/orders' || str_starts_with($returnTo, '/apparel/orders?'))) {
            return $returnTo;
        }

        return route('apparel.orders.index');
    }
}
