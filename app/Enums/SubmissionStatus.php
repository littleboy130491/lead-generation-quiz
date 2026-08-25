<?php

namespace App\Enums;

enum SubmissionStatus: string
{
    case InProgress = 'in_progress';
    case AwaitingContact = 'awaiting_contact';
    case Completed = 'completed';
    case HeldForReview = 'held_for_review';
    case Spam = 'spam';
    case Abandoned = 'abandoned';
}
