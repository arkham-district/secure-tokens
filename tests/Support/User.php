<?php

namespace ArkhamDistrict\ApiKeys\Tests\Support;

use ArkhamDistrict\ApiKeys\Contracts\HasApiKeys as HasApiKeysContract;
use ArkhamDistrict\ApiKeys\HasApiKeys;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements HasApiKeysContract
{
    use HasApiKeys;

    protected $guarded = [];
}
