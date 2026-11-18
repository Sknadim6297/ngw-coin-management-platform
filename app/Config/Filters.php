<?php

namespace Config;

use App\Filters\AdminFilter;
use App\Filters\GuestFilter;
use App\Filters\MemberFilter;
use App\Filters\SupportViewFilter;
use CodeIgniter\Config\Filters as BaseFilters;
use CodeIgniter\Filters\Cors;
use CodeIgniter\Filters\CSRF;
use CodeIgniter\Filters\DebugToolbar;
use CodeIgniter\Filters\ForceHTTPS;
use CodeIgniter\Filters\Honeypot;
use CodeIgniter\Filters\InvalidChars;
use CodeIgniter\Filters\PageCache;
use CodeIgniter\Filters\PerformanceMetrics;
use CodeIgniter\Filters\SecureHeaders;
use CodeIgniter\Shield\Filters\AuthRates;
use CodeIgniter\Shield\Filters\ChainAuth;
use CodeIgniter\Shield\Filters\GroupFilter;
use CodeIgniter\Shield\Filters\PermissionFilter;
use CodeIgniter\Shield\Filters\SessionAuth;

class Filters extends BaseFilters
{
    public array $aliases = [
        'csrf'          => CSRF::class,
        'toolbar'       => DebugToolbar::class,
        'honeypot'      => Honeypot::class,
        'invalidchars'  => InvalidChars::class,
        'secureheaders' => SecureHeaders::class,
        'cors'          => Cors::class,
        'forcehttps'    => ForceHTTPS::class,
        'pagecache'     => PageCache::class,
        'performance'   => PerformanceMetrics::class,
        'session'       => SessionAuth::class,
        'chain'         => ChainAuth::class,
        'auth-rates'    => AuthRates::class,
        'group'         => GroupFilter::class,
        'permission'    => PermissionFilter::class,
        'admin'         => AdminFilter::class,
        'member'        => MemberFilter::class,
        'guest'         => GuestFilter::class,
        'supportView'   => SupportViewFilter::class,
    ];

    public array $required = [
        'before' => [
            'forcehttps',
            'pagecache',
        ],
        'after' => ENVIRONMENT === 'production'
            ? [
                'pagecache',
                'performance',
            ]
            : [
                'pagecache',
                'performance',
                'toolbar',
            ],
    ];

    public array $globals = [
        'before' => [
            'csrf' => [
                // Feature tests exercise controllers without browser CSRF cookies.
                'except' => ENVIRONMENT === 'testing' ? ['*'] : [],
            ],
        ],
        'after' => [
            'secureheaders',
        ],
    ];


    public array $methods = [];

    public array $filters = [
        'auth-rates' => [
            'before' => [
                'login*',
                'register*',
                'forgot-password*',
                'reset-password*',
                'confirm-email-change*',
                'admin/login*',
                'admin/members/*/impersonate',
                'support-view/start',
                'support-view/end',
                'member/profile',
                'member/profile/*',
                'member/security/password',
                'member/purchases',
                'admin/purchases/*/approve',
                'admin/purchases/*/reject',
                'member/deposits',
                'member/p2p/lookup',
                'member/p2p/preview',
                'member/p2p/transfer',
                'member/withdrawals',
                'member/withdrawals/preview',
                'admin/withdrawals/*/approve',
                'admin/withdrawals/*/reject',
                'admin/withdrawals/*/record-payout',
                'admin/withdrawals/*/verify-payout',
                'member/coin-selling',
                'member/coin-selling/preview',
                'admin/coins/selling/*/approve',
                'admin/coins/selling/*/reject',
                'member/purchases/*/link-deposit',
                'admin/deposits/*/under-review',
                'admin/deposits/*/reject',
                'admin/deposits/*/attempt-verify',
                'admin/deposits/*/manual-verify',
                'admin/deposits/*/approve-payment',
                'admin/networks',
                'admin/payment-health/probe',
                'admin/payment-audit/*/retry-verify',
                'admin/payment-audit/*/integrity-recheck',
                'admin/finance/alerts/*/ack',
                'admin/finance/monitor/run',
                'admin/finance/remediations',
                'admin/finance/remediations/*/approve',
                'admin/finance/remediations/*/reject',
            ],
        ],
    ];
}
