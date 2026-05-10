<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

final readonly class SeoMetadata
{
    /**
     * @param  array<string, string|null>  $overrides
     */
    public static function forRequest(Request $request, array $overrides = []): self
    {
        $routeName = $request->route()?->getName();
        $defaults = self::defaultsFor($routeName);
        $title = $overrides['title'] ?? $defaults['title'];
        $description = $overrides['description'] ?? $defaults['description'];

        return new self(
            title: self::formatTitle($title),
            description: Str::limit($description, 160, ''),
            url: $overrides['url'] ?? $request->fullUrl(),
            image: $overrides['image'] ?? self::previewImageUrl($title, $description),
            type: $overrides['type'] ?? 'website',
            siteName: self::siteName(),
            robots: $overrides['robots'] ?? self::robotsFor($routeName),
        );
    }

    public function __construct(
        public string $title,
        public string $description,
        public string $url,
        public string $image,
        public string $type,
        public string $siteName,
        public string $robots,
    ) {}

    /**
     * @return array{title: string, description: string}
     */
    private static function defaultsFor(?string $routeName): array
    {
        return match (true) {
            $routeName === 'login' => [
                'title' => 'Secure Login',
                'description' => 'Sign in to DigitoFina for secure financial operations, member services, loans, accounts, teller drawers, and reporting.',
            ],
            $routeName === 'dashboard' => [
                'title' => 'Operations Dashboard',
                'description' => 'Monitor savings balances, active accounts, loan portfolio health, teller activity, approvals, and financial alerts in DigitoFina.',
            ],
            $routeName === 'member.dashboard' => [
                'title' => 'Member Portal',
                'description' => 'View member account balances, loan progress, repayments, and self-service financial details in DigitoFina.',
            ],
            Str::is('clients.*', (string) $routeName) => [
                'title' => 'Client Management',
                'description' => 'Register, manage, verify, and review client profiles, KYC records, accounts, and loan relationships in DigitoFina.',
            ],
            Str::is('accounts.*', (string) $routeName) => [
                'title' => 'Account Management',
                'description' => 'Open, track, and review savings, loan, shares, and fixed deposit accounts across branch operations in DigitoFina.',
            ],
            Str::is('loan-products.*', (string) $routeName) => [
                'title' => 'Loan Products',
                'description' => 'Configure and review loan products, limits, rates, repayment frequencies, and credit terms in DigitoFina.',
            ],
            Str::is('loans.*', (string) $routeName) => [
                'title' => 'Loan Management',
                'description' => 'Process loan applications, approvals, disbursements, repayment schedules, and credit portfolio workflows in DigitoFina.',
            ],
            $routeName === 'teller.index' => [
                'title' => 'Teller Drawer',
                'description' => 'Open drawers, post transactions, reconcile balances, and close teller sessions with DigitoFina cash controls.',
            ],
            $routeName === 'reports.index' => [
                'title' => 'Financial Reports',
                'description' => 'Review operational, financial, portfolio, teller, and branch performance reports in DigitoFina.',
            ],
            $routeName === 'receipts.show' => [
                'title' => 'Transaction Receipt',
                'description' => 'Review a secure DigitoFina receipt for a posted financial transaction.',
            ],
            default => [
                'title' => 'Financial Management Core',
                'description' => 'DigitoFina helps financial institutions manage clients, accounts, loans, teller operations, reporting, and member services.',
            ],
        };
    }

    private static function formatTitle(string $title): string
    {
        $siteName = self::siteName();

        if (Str::contains($title, $siteName)) {
            return $title;
        }

        return "{$title} | {$siteName}";
    }

    private static function previewImageUrl(string $title, string $description): string
    {
        return route('link-preview', [
            'title' => Str::limit($title, 70, ''),
            'description' => Str::limit($description, 120, ''),
        ]);
    }

    private static function robotsFor(?string $routeName): string
    {
        return $routeName === 'login' ? 'index, follow' : 'noindex, nofollow';
    }

    private static function siteName(): string
    {
        $siteName = config('app.name', 'DigitoFina');

        return $siteName === 'Laravel' ? 'DigitoFina' : $siteName;
    }
}
