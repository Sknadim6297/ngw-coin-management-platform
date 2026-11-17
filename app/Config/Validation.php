<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;
use CodeIgniter\Validation\StrictRules\CreditCardRules;
use CodeIgniter\Validation\StrictRules\FileRules;
use CodeIgniter\Validation\StrictRules\FormatRules;
use CodeIgniter\Validation\StrictRules\Rules;

class Validation extends BaseConfig
{
    // --------------------------------------------------------------------
    // Setup
    // --------------------------------------------------------------------

    /**
     * Stores the classes that contain the
     * rules that are available.
     *
     * @var list<string>
     */
    public array $ruleSets = [
        Rules::class,
        FormatRules::class,
        FileRules::class,
        CreditCardRules::class,
        \App\Validation\MemberRules::class,
    ];

    public array $memberRegistration = [
        'member_name' => [
            'label' => 'Member Name',
            'rules' => 'required|min_length[2]|max_length[120]',
        ],
        'sponsor_id' => [
            'label' => 'Sponsor ID',
            'rules' => 'required|max_length[32]|valid_sponsor_id',
        ],
        'mobile' => [
            'label' => 'Mobile Number',
            'rules' => 'required|min_length[8]|max_length[20]|regex_match[/^[0-9+\-\s]+$/]',
        ],
        'email' => [
            'label' => 'Email Address',
            'rules' => 'required|valid_email|max_length[255]',
        ],
        'usdt_wallet' => [
            'label' => 'USDT Wallet Address',
            'rules' => 'required|max_length[255]',
        ],
        'password' => [
            'label' => 'Password',
            'rules' => 'required|min_length[8]|max_length[72]',
        ],
        'password_confirm' => [
            'label' => 'Confirm Password',
            'rules' => 'required|matches[password]',
        ],
    ];

    public array $memberProfileUpdate = [
        'member_name' => [
            'label' => 'Full Name',
            'rules' => 'required|min_length[2]|max_length[120]',
        ],
        'mobile' => [
            'label' => 'Mobile Number',
            'rules' => 'required|min_length[8]|max_length[20]|regex_match[/^[0-9+\-\s]+$/]',
        ],
        'email' => [
            'label' => 'Email Address',
            'rules' => 'required|valid_email|max_length[255]',
        ],
        'usdt_wallet' => [
            'label' => 'USDT Wallet Address',
            'rules' => 'required|max_length[255]|regex_match[/^0x[a-fA-F0-9]{40}$/]',
        ],
        'current_password' => [
            'label' => 'Current Password',
            'rules' => 'required',
        ],
    ];

    public array $memberLogin = [
        'member_id' => [
            'label' => 'Member ID',
            'rules' => 'required|max_length[32]',
        ],
        'password' => [
            'label' => 'Password',
            'rules' => 'required',
        ],
    ];

    public array $adminLogin = [
        'email' => [
            'label' => 'Email',
            'rules' => 'required|valid_email',
        ],
        'password' => [
            'label' => 'Password',
            'rules' => 'required',
        ],
    ];

    public array $forgotPassword = [
        'email' => [
            'label' => 'Email Address',
            'rules' => 'required|valid_email',
        ],
    ];

    public array $resetPassword = [
        'password' => [
            'label' => 'Password',
            'rules' => 'required|min_length[8]|max_length[72]',
        ],
        'password_confirm' => [
            'label' => 'Confirm Password',
            'rules' => 'required|matches[password]',
        ],
    ];

    public array $coinInventoryAdd = [
        'quantity' => [
            'label' => 'Coin Quantity',
            'rules' => 'required|decimal|greater_than[0]',
        ],
        'reference' => [
            'label' => 'Reference / Source Note',
            'rules' => 'permit_empty|max_length[255]',
        ],
        'remarks' => [
            'label' => 'Admin Remarks',
            'rules' => 'permit_empty|max_length[1000]',
        ],
        'confirm' => [
            'label' => 'Confirmation',
            'rules' => 'required|in_list[1]',
            'errors' => [
                'required' => 'Please confirm this internal inventory entry.',
                'in_list'  => 'Please confirm this internal inventory entry.',
            ],
        ],
        'idempotency_key' => [
            'label' => 'Submission Key',
            'rules' => 'required|max_length[64]',
        ],
    ];

    public array $coinPriceUpdate = [
        'new_price' => [
            'label' => 'New Price (USDT per Coin)',
            'rules' => 'required|decimal|greater_than[0]',
        ],
        'reason' => [
            'label' => 'Reason for Change',
            'rules' => 'required|min_length[3]|max_length[500]',
        ],
        'confirm' => [
            'label' => 'Confirmation',
            'rules' => 'required|in_list[1]',
            'errors' => [
                'required' => 'Please confirm this price change.',
                'in_list'  => 'Please confirm this price change.',
            ],
        ],
    ];

    public array $coinPurchaseRequest = [
        'quantity' => [
            'label' => 'Coin Quantity',
            'rules' => 'required|decimal|greater_than[0]',
        ],
        'member_note' => [
            'label' => 'Note',
            'rules' => 'permit_empty|max_length[500]',
        ],
        'confirm' => [
            'label' => 'Confirmation',
            'rules' => 'required|in_list[1]',
            'errors' => [
                'required' => 'Please confirm this purchase request.',
                'in_list'  => 'Please confirm this purchase request.',
            ],
        ],
        'idempotency_key' => [
            'label' => 'Submission Key',
            'rules' => 'required|max_length[64]',
        ],
    ];

    public array $coinPurchaseReject = [
        'rejection_reason' => [
            'label' => 'Rejection Reason',
            'rules' => 'required|min_length[3]|max_length[500]',
        ],
    ];

    public array $coinPurchaseApprove = [
        'confirm' => [
            'label' => 'Confirmation',
            'rules' => 'required|in_list[1]',
            'errors' => [
                'required' => 'Please confirm approval of this purchase request.',
                'in_list'  => 'Please confirm approval of this purchase request.',
            ],
        ],
    ];

    public array $usdtDepositSubmit = [
        'network_id' => [
            'label' => 'Network',
            'rules' => 'required|is_natural_no_zero',
        ],
        'expected_amount_usdt' => [
            'label' => 'Expected USDT Amount',
            'rules' => 'required|decimal|greater_than[0]',
        ],
        'tx_hash' => [
            'label' => 'Transaction Hash',
            'rules' => 'required|min_length[10]|max_length[128]',
        ],
        'confirm' => [
            'label' => 'Confirmation',
            'rules' => 'required|in_list[1]',
            'errors' => [
                'required' => 'Please confirm you understand this is not payment verification.',
                'in_list'  => 'Please confirm you understand this is not payment verification.',
            ],
        ],
        'idempotency_key' => [
            'label' => 'Submission Key',
            'rules' => 'required|max_length[64]',
        ],
        'evidence' => [
            'label' => 'Payment Evidence',
            'rules' => 'permit_empty|uploaded[evidence]|max_size[evidence,5120]|ext_in[evidence,jpg,jpeg,png,webp,pdf]|mime_in[evidence,image/jpg,image/jpeg,image/png,image/webp,application/pdf]',
        ],
    ];

    public array $usdtDepositReject = [
        'rejection_reason' => [
            'label' => 'Rejection Reason',
            'rules' => 'required|min_length[3]|max_length[500]',
        ],
    ];

    public array $usdtDepositNotes = [
        'review_notes' => [
            'label' => 'Review Notes',
            'rules' => 'required|min_length[3]|max_length[2000]',
        ],
    ];

    public array $usdtNetworkSave = [
        'network_key' => [
            'label' => 'Network Key',
            'rules' => 'required|min_length[2]|max_length[40]|regex_match[/^[a-z0-9_\-]+$/]',
        ],
        'network_name' => [
            'label' => 'Network Name',
            'rules' => 'required|min_length[2]|max_length[120]',
        ],
        'token_symbol' => [
            'label' => 'Token Symbol',
            'rules' => 'permit_empty|max_length[20]',
        ],
        'token_contract_address' => [
            'label' => 'Token Contract',
            'rules' => 'permit_empty|max_length[128]',
        ],
        'token_decimals' => [
            'label' => 'Token Decimals',
            'rules' => 'required|integer|greater_than_equal_to[0]|less_than_equal_to[36]',
        ],
        'destination_wallet' => [
            'label' => 'Destination Wallet',
            'rules' => 'permit_empty|max_length[128]',
        ],
        'required_confirmations' => [
            'label' => 'Required Confirmations',
            'rules' => 'required|integer|greater_than[0]',
        ],
        'min_deposit_usdt' => [
            'label' => 'Minimum Deposit',
            'rules' => 'required|decimal|greater_than[0]',
        ],
        'max_deposit_usdt' => [
            'label' => 'Maximum Deposit',
            'rules' => 'required|decimal|greater_than[0]',
        ],
        'sort_order' => [
            'label' => 'Sort Order',
            'rules' => 'permit_empty|integer',
        ],
    ];

    /**
     * Specifies the views that are used to display the
     * errors.
     *
     * @var array<string, string>
     */
    public array $templates = [
        'list'   => 'CodeIgniter\Validation\Views\list',
        'single' => 'CodeIgniter\Validation\Views\single',
    ];

    // --------------------------------------------------------------------
    // Rules
    // --------------------------------------------------------------------
}
