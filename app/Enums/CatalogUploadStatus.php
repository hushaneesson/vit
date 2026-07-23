<?php

namespace App\Enums;

enum CatalogUploadStatus: string
{
    case Uploaded = 'uploaded';
    case Mapping = 'mapping';
    case Queued = 'queued';
    case Processing = 'processing';
    case ProcessingItems = 'processing_items';
    case Completed = 'completed';
    case Failed = 'failed';
}
