<?php

namespace App\Models;

use App\Concerns\AddUuid;
use Illuminate\Database\Eloquent\Model;

class FileUpload extends Model
{
    use AddUuid;

    protected $fillable = ['name', 'folder_path', 'url'];
}
