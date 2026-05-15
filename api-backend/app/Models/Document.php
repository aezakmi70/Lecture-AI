<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    protected $fillable = ['filename', 'file_path', 'text'];

    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class);
    }
}
