<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class LinkPreviewController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $title = e(Str::limit($request->string('title')->trim()->value() ?: 'DigitoFina', 72, ''));
        $description = e(Str::limit($request->string('description')->trim()->value() ?: 'Financial management core for clients, accounts, loans, teller operations, and reporting.', 128, ''));

        $svg = <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" width="1200" height="630" viewBox="0 0 1200 630" role="img" aria-labelledby="title description">
            <title id="title">{$title}</title>
            <desc id="description">{$description}</desc>
            <defs>
                <linearGradient id="surface" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0" stop-color="#0f172a"/>
                    <stop offset="0.58" stop-color="#1d4ed8"/>
                    <stop offset="1" stop-color="#047857"/>
                </linearGradient>
            </defs>
            <rect width="1200" height="630" fill="url(#surface)"/>
            <rect x="72" y="72" width="1056" height="486" rx="28" fill="#ffffff" fill-opacity="0.94"/>
            <rect x="112" y="112" width="96" height="96" rx="16" fill="#1d4ed8"/>
            <text x="160" y="174" fill="#ffffff" font-family="Arial, Helvetica, sans-serif" font-size="40" font-weight="700" text-anchor="middle">DF</text>
            <text x="236" y="152" fill="#0f172a" font-family="Arial, Helvetica, sans-serif" font-size="34" font-weight="700">DigitoFina</text>
            <text x="236" y="192" fill="#475569" font-family="Arial, Helvetica, sans-serif" font-size="22">Financial Management Core</text>
            <text x="112" y="332" fill="#0f172a" font-family="Arial, Helvetica, sans-serif" font-size="62" font-weight="700">{$title}</text>
            <text x="112" y="398" fill="#334155" font-family="Arial, Helvetica, sans-serif" font-size="30">{$description}</text>
            <rect x="112" y="466" width="236" height="14" rx="7" fill="#059669"/>
            <rect x="374" y="466" width="150" height="14" rx="7" fill="#1d4ed8"/>
        </svg>
        SVG;

        return response($svg, Response::HTTP_OK, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
