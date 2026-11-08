<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Represents retained evidence failures that block automatic savings processing.
 */
class SavingsException extends Model
{
    public $timestamps = false;
}
