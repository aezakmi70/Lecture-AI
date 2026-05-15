<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Chunk extends Model
{
    protected $fillable = ['document_id', 'position', 'chunk_text', 'embedding'];

    protected $casts = [
        'embedding' => 'array'
    ];
}
