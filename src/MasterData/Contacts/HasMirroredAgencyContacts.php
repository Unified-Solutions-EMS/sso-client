<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Contacts;

use Illuminate\Support\Collection;

/**
 * Agency contacts for the app's Company model, read from the mirror:
 *
 *     use \Unified\SsoClient\MasterData\Contacts\HasMirroredAgencyContacts;
 *
 *     $company->agencyContacts();
 *     $company->primaryAgencyContact();
 *     $company->agencyContactsOfType('medical_director');
 *
 * Read-only: SSO's Agency contacts page owns the list.
 */
trait HasMirroredAgencyContacts
{
    /**
     * @return Collection<int, object>
     */
    public function agencyContacts(): Collection
    {
        return ContactCatalog::activeForCompany((int) $this->getKey());
    }

    public function primaryAgencyContact(): ?object
    {
        return ContactCatalog::primaryForCompany((int) $this->getKey());
    }

    /**
     * @return Collection<int, object>
     */
    public function agencyContactsOfType(string $type): Collection
    {
        return ContactCatalog::ofType((int) $this->getKey(), $type);
    }
}
