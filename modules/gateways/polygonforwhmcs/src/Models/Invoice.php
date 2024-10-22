<?php

namespace PolygonForWHMCS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'tblinvoices';


    /**
     * Invoice transactions.
     *
     * @return  HasMany
     */
    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'invoiceid');
    }
}
