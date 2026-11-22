<?php

namespace Liberu\Foundation\Integrations\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Liberu\Foundation\Integrations\Models\AccountingIntegration;
use Liberu\Foundation\Integrations\Services\AccountingService;

class AccountingIntegrationController extends Controller
{
    public function __construct(protected AccountingService $accountingService) {}

    public function connect(Request $request)
    {
        $validated = $request->validate([
            'platform' => 'required|in:quickbooks,xero',
            'credentials' => 'required|array',
        ]);

        try {
            $connection = $this->accountingService->connectPlatform($validated['platform'], $validated['credentials']);

            $user = Auth::user();

            AccountingIntegration::create([
                'user_id' => $user->id,
                'platform' => $validated['platform'],
                'connection_details' => $connection,
            ]);

            return response()->json(['message' => 'Successfully connected to '.$validated['platform']]);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function disconnect(AccountingIntegration $integration)
    {
        $integration->delete();

        return response()->json(['message' => 'Successfully disconnected from '.$integration->platform]);
    }

    public function status(AccountingIntegration $integration)
    {
        return response()->json([
            'platform' => $integration->platform,
            'connected' => true,
            'last_synced' => $integration->last_synced,
        ]);
    }
}
