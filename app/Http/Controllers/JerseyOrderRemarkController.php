<?php

namespace App\Http\Controllers;

use App\Models\JerseyOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class JerseyOrderRemarkController extends Controller
{
    public function update(Request $request, JerseyOrder $jerseyOrder): RedirectResponse
    {
        abort_unless($request->user()?->allows('apparel.remarks.edit'), 403);

        $validated = $request->validate([
            'remark' => ['nullable', 'string', 'max:5000'],
            'return_to' => ['nullable', 'string', 'max:2000'],
        ]);

        $remark = trim((string) ($validated['remark'] ?? ''));

        $jerseyOrder->forceFill([
            'remark' => $remark === '' ? null : $remark,
            'remark_updated_at' => $remark === '' ? null : now(),
            'remark_updated_by' => $remark === '' ? null : $request->user()->id,
            'remark_processed_at' => null,
            'remark_processed_by' => null,
        ])->save();

        return redirect()
            ->to($this->safeReturnTo($validated['return_to'] ?? null))
            ->with('ok', 'Remark saved.');
    }

    private function safeReturnTo(?string $returnTo): string
    {
        if (is_string($returnTo) && ($returnTo === '/apparel/orders' || str_starts_with($returnTo, '/apparel/orders?'))) {
            return $returnTo;
        }

        return route('apparel.orders.index');
    }
}
