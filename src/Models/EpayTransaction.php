<?php

namespace Reach\ResrvPaymentEpay\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Reach\StatamicResrv\Models\Reservation;

/**
 * One ticket issued by epay for a reservation payment attempt.
 *
 * status: pending (ticket issued), success (approved and hash verified),
 * failed (declined or technical error), invalid (hash verification failed).
 */
class EpayTransaction extends Model
{
    protected $table = 'resrv_epay_transactions';

    protected $guarded = [];

    protected $hidden = ['transaction_ticket'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'response' => 'array',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
