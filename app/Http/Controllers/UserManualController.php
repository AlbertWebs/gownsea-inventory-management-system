<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class UserManualController extends Controller
{
    public function __invoke(): InertiaResponse
    {
        $path = base_path('USER_MANUAL.md');
        abort_unless(File::exists($path), Response::HTTP_NOT_FOUND);

        return Inertia::render('Help/UserManual', [
            'manualHtml' => Str::markdown(File::get($path)),
        ]);
    }

    public function download(): BinaryFileResponse
    {
        $path = base_path('USER_MANUAL.md');
        abort_unless(File::exists($path), Response::HTTP_NOT_FOUND);

        return response()->download($path, 'USER_MANUAL.md', [
            'Content-Type' => 'text/markdown; charset=UTF-8',
        ]);
    }
}
