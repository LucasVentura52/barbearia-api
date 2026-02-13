<?php

namespace App\Support;

use App\Models\Company;

class TenantContext
{
    /**
     * @var \App\Models\Company|null
     */
    private $company;

    public function setCompany(?Company $company): void
    {
        $this->company = $company;
    }

    public function company(): ?Company
    {
        return $this->company;
    }

    public function id(): ?int
    {
        return $this->company ? (int) $this->company->id : null;
    }
}
