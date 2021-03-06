<?php

namespace IO\Helper;

use Plenty\Modules\Accounting\Vat\Contracts\VatRepositoryContract;
use Plenty\Modules\Webshop\Contracts\ContactRepositoryContract;

/**
 * Class VatConverter
 *
 * @package IO\Helper
 *
 * @depreacted since 5.0.0 will be removed in 6.0.0
 */
class VatConverter
{
    /** @var VatRepositoryContract $vatRepo */
    private $vatRepo;

    /**
     * VatConverter constructor.
     * @param VatRepositoryContract $vatRepo
     */
    public function __construct(VatRepositoryContract $vatRepo)
    {
        $this->vatRepo = $vatRepo;
    }

    /**
     * @return mixed
     */
    public function getDefaultVat()
    {
        $defaultVat = $this->vatRepo->getStandardVat(Utils::getPlentyId())->vatRates->first();
        return $defaultVat;
    }

    /**
     * @param float $amount
     *
     * @return float|int
     * @throws \ErrorException
     */
    public function convertToGross($amount)
    {
        /** @var ContactRepositoryContract $contactRepository */
        $contactRepository = pluginApp(ContactRepositoryContract::class);
        $contactClassData = $contactRepository->getContactClassData($contactRepository->getContactClassId());

        if(isset($contactClassData['showNetPrice']) && $contactClassData['showNetPrice'])
        {
            return $amount + (($amount * $this->getDefaultVat()->vatRate) / 100);
        }

        return $amount;
    }
}
