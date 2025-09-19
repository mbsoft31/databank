<?php
// app/Enums/ItemStatus.php
namespace App\Enums;

enum ItemStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case ChangesRequested = 'changes_requested';
    case Approved = 'approved';
    case Published = 'published';
    case Archived = 'archived';
}
