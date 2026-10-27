<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reply to the sender, or (`is_internal`) a private note for the people
 * who manage feedback.
 */
#[Fillable(['body', 'is_internal'])]
class FeedbackReply extends Model
{
    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return ['is_internal' => 'boolean'];
    }

    public function feedback(): BelongsTo
    {
        return $this->belongsTo(Feedback::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
