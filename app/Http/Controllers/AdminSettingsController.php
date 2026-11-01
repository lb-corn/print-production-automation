<?php
namespace App\Http\Controllers;

use App\Services\ArtworkRenameStore;
use Illuminate\Http\Request;

class AdminSettingsController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()?->role==='admin',403);
        $section=$request->query('section','artwork');
        abort_unless(in_array($section,['artwork','reset'],true),404);
        return $section==='reset' ? app(AdminController::class)->resetPage()
            : app(ArtworkNamingController::class)->index($request,app(ArtworkRenameStore::class));
    }
}
