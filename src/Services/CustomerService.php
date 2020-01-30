<?php //strict

namespace IO\Services;

use IO\Api\Resources\CustomerAddressResource;
use IO\Builder\Order\AddressType;
use IO\Builder\Order\OrderType;
use IO\Constants\ShippingCountry;
use IO\Extensions\Filters\PropertyNameFilter;
use IO\Extensions\Mail\SendMail;
use IO\Helper\ArrayHelper;
use IO\Helper\MemoryCache;
use IO\Helper\Utils;
use IO\Models\LocalizedOrder;
use IO\Validators\Customer\AddressValidator;
use Plenty\Modules\Account\Address\Contracts\AddressRepositoryContract;
use Plenty\Modules\Account\Address\Models\Address;
use Plenty\Modules\Account\Address\Models\AddressOption;
use Plenty\Modules\Account\Contact\Contracts\ContactAddressRepositoryContract;
use Plenty\Modules\Account\Contact\Contracts\ContactAccountRepositoryContract;
use Plenty\Modules\Account\Contact\Contracts\ContactClassRepositoryContract;
use Plenty\Modules\Account\Contact\Contracts\ContactRepositoryContract as CoreContactRepositoryContract;
use Plenty\Modules\Account\Contact\Models\Contact;
use Plenty\Modules\Account\Contact\Models\ContactOption;
use Plenty\Modules\Account\Models\Account;
use Plenty\Modules\Authorization\Services\AuthHelper;
use Plenty\Modules\Basket\Events\Basket\AfterBasketChanged;
use Plenty\Modules\Frontend\Events\FrontendCustomerAddressChanged;
use Plenty\Modules\Frontend\Events\FrontendUpdateDeliveryAddress;
use Plenty\Modules\Frontend\Events\FrontendUpdateInvoiceAddress;
use Plenty\Modules\Frontend\Services\AccountService;
use Plenty\Modules\Helper\AutomaticEmail\Models\AutomaticEmailTemplate;
use Plenty\Modules\Helper\AutomaticEmail\Models\AutomaticEmailContact;
use Plenty\Modules\System\Contracts\WebstoreConfigurationRepositoryContract;
use Plenty\Modules\System\Models\WebstoreConfiguration;
use Plenty\Modules\Webshop\Contracts\ContactRepositoryContract;
use Plenty\Modules\Webshop\Contracts\SessionStorageRepositoryContract;
use Plenty\Modules\Webshop\Repositories\WebstoreConfigurationRepository;
use Plenty\Modules\Webshop\Template\Contracts\TemplateConfigRepositoryContract;
use Plenty\Plugin\Events\Dispatcher;

/**
 * Class CustomerService
 * @package IO\Services
 */
class CustomerService
{
    use MemoryCache;
    use SendMail;

    /** @var ContactAccountRepositoryContract $accountRepository */
    private $accountRepository;

    /** @var CoreContactRepositoryContract */
    private $coreContactRepository;

    /** @var ContactRepositoryContract */
    private $contactRepository;

    /** @var ContactAddressRepositoryContract */
    private $contactAddressRepository;

    /** @var AddressRepositoryContract */
    private $addressRepository;

    /** @var ContactClassRepositoryContract */
    private $contactClassRepository;

    /** @var SessionStorageRepositoryContract */
    private $sessionStorageRepository;

    /**
     * CustomerService constructor.
     * @param ContactAccountRepositoryContract $accountRepository
     * @param CoreContactRepositoryContract $coreContactRepository
     * @param ContactRepositoryContract $contactRepository
     * @param ContactAddressRepositoryContract $contactAddressRepository
     * @param AddressRepositoryContract $addressRepository
     * @param ContactClassRepositoryContract $contactClassRepository
     * @param SessionStorageRepositoryContract $sessionStorageRepository
     */
    public function __construct(
        ContactAccountRepositoryContract $accountRepository,
        CoreContactRepositoryContract $coreContactRepository,
        ContactRepositoryContract $contactRepository,
        ContactAddressRepositoryContract $contactAddressRepository,
        AddressRepositoryContract $addressRepository,
        ContactClassRepositoryContract $contactClassRepository,
        SessionStorageRepositoryContract $sessionStorageRepository
    ) {
        $this->accountRepository = $accountRepository;
        $this->coreContactRepository = $coreContactRepository;
        $this->contactRepository = $contactRepository;
        $this->contactAddressRepository = $contactAddressRepository;
        $this->addressRepository = $addressRepository;
        $this->contactClassRepository = $contactClassRepository;
        $this->sessionStorageRepository = $sessionStorageRepository;
    }

    /**
     * Get the ID of the current contact from the session
     * @return int
     * @deprecated since 5.0.0 will be removed in 6.0.0
     * @see \Plenty\Modules\Webshop\Contracts\ContactRepositoryContract::getContactId()
     */
    public function getContactId(): int
    {
        return $this->contactRepository->getContactId();
    }

    /**
     * @param int $contactClassId
     * @return array|null
     * @deprecated since 5.0.0 will be removed in 6.0.0
     * @see \Plenty\Modules\Webshop\Contracts\ContactRepositoryContract::getContactClassData()
     */
    public function getContactClassData($contactClassId)
    {
        return $this->contactRepository->getContactClassData($contactClassId);
    }

    /**
     * @return bool
     * @deprecated since 5.0.0 will be removed in 6.0.0
     * @see \Plenty\Modules\Webshop\Contracts\ContactRepositoryContract::showNetPrices()
     */
    public function showNetPrices(): bool
    {
        return $this->contactRepository->showNetPrices();
    }

    /**
     * @param int $contactId
     * @return bool
     */
    public function showNetPricesByContactId(int $contactId): bool
    {
        return $this->fromMemoryCache(
            "showNetPrices.$contactId",
            function () use ($contactId) {
                /** @var AuthHelper $authHelper */
                $authHelper = pluginApp(AuthHelper::class);

                if ($contactId > 0) {
                    $contact = $authHelper->processUnguarded(
                        function () use ($contactId) {
                            return $this->coreContactRepository->findContactById($contactId);
                        }
                    );

                    if ($contact !== null) {
                        $contactClass = $this->contactRepository->getContactClassData($contact->classId);

                        if ($contactClass !== null) {
                            return $contactClass['showNetPrice'];
                        }
                    }
                } else {
                    $contactClassId = $this->contactRepository->getDefaultContactClassId();
                    $contactClass = $this->contactRepository->getContactClassData($contactClassId);

                    if ($contactClass !== null) {
                        return $contactClass['showNetPrice'];
                    }
                }

                return false;
            }
        );
    }

    /**
     * @return int
     */
    public function getContactClassMinimumOrderQuantity(): int
    {
        $contact = $this->contactRepository->getContact();

        if ($contact instanceof Contact) {
            $contactClassId = $contact->classId;

            $contactClass = $this->contactRepository->getContactClassData($contactClassId);

            if (is_array($contactClass) && count($contactClass) && isset($contactClass['minItemQuantity'])) {
                return (int)$contactClass['minItemQuantity'];
            }
        }

        return 0;
    }

    /**
     * Create a contact with addresses if specified
     * @param array $contactData
     * @param array $billingAddressData
     * @param array $deliveryAddressData
     * @return Contact
     * @throws \Plenty\Exceptions\ValidationException
     */
    public function registerCustomer(array $contactData, $billingAddressData = null, $deliveryAddressData = null)
    {
        /** @var BasketService $basketService */
        $basketService = pluginApp(BasketService::class);

        /** @var AuthenticationService $authenticationService */
        $authenticationService = pluginApp(AuthenticationService::class);

        $newBillingAddress = null;
        $newDeliveryAddress = null;

        $guestBillingAddress = null;
        //$guestBillingAddressId = $this->sessionStorageRepository->getSessionValue(SessionStorageRepositoryContract::BILLING_ADDRESS_ID);
        $guestBillingAddressId = $basketService->getBillingAddressId();
        if ((int)$guestBillingAddressId > 0) {
            $guestBillingAddress = $this->addressRepository->findAddressById($guestBillingAddressId);
        }

        $guestDeliveryAddress = null;
        //$guestDeliveryAddressId = $this->sessionStorageRepository->getSessionValue(SessionStorageRepositoryContract::DELIVERY_ADDRESS_ID);
        $guestDeliveryAddressId = $basketService->getDeliveryAddressId();
        if ((int)$guestDeliveryAddressId > 0) {
            $guestDeliveryAddress = $this->addressRepository->findAddressById($guestDeliveryAddressId);
        }

        $contact = $this->createContact($contactData);

        if (!is_null($contact) && $contact->id > 0) {
            //Login
            $authenticationService->loginWithContactId($contact->id, (string)$contactData['password']);

            if ($guestBillingAddress !== null) {
                $newBillingAddress = $this->createAddress(
                    $guestBillingAddress->toArray(),
                    AddressType::BILLING
                );
                //$this->sessionStorageRepository->setSessionValue(SessionStorageRepositoryContract::BILLING_ADDRESS_ID, $newBillingAddress->id);
                $basketService->setBillingAddressId($newBillingAddress->id);
            }

            if ($guestDeliveryAddress !== null) {
                $newDeliveryAddress = $this->createAddress(
                    $guestDeliveryAddress->toArray(),
                    AddressType::DELIVERY
                );
                //$this->sessionStorageRepository->setSessionValue(SessionStorageRepositoryContract::DELIVERY_ADDRESS_ID, $newDeliveryAddress->id);
                $basketService->setDeliveryAddressId($newDeliveryAddress->id);
            }

            if ($billingAddressData !== null) {
                $newBillingAddress = $this->createAddress($billingAddressData, AddressType::BILLING);
                //$this->sessionStorageRepository->setSessionValue(SessionStorageRepositoryContract::BILLING_ADDRESS_ID, $newBillingAddress->id);
                $basketService->setBillingAddressId($newBillingAddress->id);
            }

            if ($deliveryAddressData !== null) {
                $newDeliveryAddress = $this->createAddress($deliveryAddressData, AddressType::DELIVERY);
                //$this->sessionStorageRepository->setSessionValue(SessionStorageRepositoryContract::DELIVERY_ADDRESS_ID, $newDeliveryAddress->id);
                $basketService->setDeliveryAddressId($newDeliveryAddress->id);
            }

            if ($newBillingAddress instanceof Address) {
                $contact = $this->updateContactWithAddressData($newBillingAddress);
            }
        }

        if ($contact instanceof Contact && $contact->id > 0) {
            $params = [
                'contactId' => $contact->id,
                'clientId' => Utils::getWebstoreId(),
                'password' => $contactData['password'],
                'language' => Utils::getLang()
            ];

            $this->sendMail(AutomaticEmailTemplate::CONTACT_REGISTRATION, AutomaticEmailContact::class, $params);
        }

        return $contact;
    }

    /**
     * @param array $accountData
     * @return Account
     * @throws \Throwable
     */
    public function createAccount($accountData): Account
    {
        /** @var AuthHelper $authHelper */
        $authHelper = pluginApp(AuthHelper::class);
        $contactId = $this->contactRepository->getContactId();
        $accountRepo = $this->accountRepository;

        return $authHelper->processUnguarded(
            function () use ($accountData, $contactId, $accountRepo) {
                return $accountRepo->createAccount($accountData, (int)$contactId);
            }
        );
    }

    /**
     * @param array $addressData
     * @return array
     */
    private function mapAddressDataToAccount($addressData): array
    {
        return [
            'companyName' => $addressData['name1'],
            'taxIdNumber' => (isset($addressData['vatNumber']) && !is_null(
                $addressData['vatNumber']
            ) ? $addressData['vatNumber'] : ''),
        ];
    }

    /**
     * Create a new contact
     * @param array $contactData
     * @return Contact|array
     */
    public function createContact(array $contactData)
    {
        $contact = null;
        $contactData['checkForExistingEmail'] = true;
        $contactData['plentyId'] = Utils::getPlentyId();

        if (!isset($contactData['lang']) || is_null($contactData['lang'])) {
            $contactData['lang'] = Utils::getLang();
        }

        try {
            $contact = $this->coreContactRepository->createContact($contactData);
        } catch (\Exception $e) {
            $contact = [
                'code' => 1,
                'message' => 'email already exists'
            ];
        }

        return $contact;
    }

    /**
     * Find the current contact by ID
     * @return null|Contact
     * @deprecated since 5.0.0 will be removed in 6.0.0
     * @see \Plenty\Modules\Webshop\Contracts\ContactRepositoryContract::getContact()
     */
    public function getContact()
    {
        return $this->contactRepository->getContact();
    }

    /**
     * @return int
     * @deprecated since 5.0.0 will be removed in 6.0.0
     * @see \Plenty\Modules\Webshop\Contracts\ContactRepositoryContract::getContactClassId()
     */
    public function getContactClassId(): int
    {
        return $this->contactRepository->getContactClassId();
    }

    /**
     * @return int
     * @deprecated since 5.0.0 will be removed in 6.0.0
     * @see \Plenty\Modules\Webshop\Contracts\ContactRepositoryContract::getDefaultContactClassId()
     */
    private function getDefaultContactClassId(): int
    {
        return $this->contactRepository->getDefaultContactClassId();
    }

    /**
     * Update a contact
     * @param array $contactData
     * @return null|Contact
     * @throws \Plenty\Exceptions\ValidationException
     */
    public function updateContact(array $contactData)
    {
        if ($this->contactRepository->getContactId() > 0) {
            return $this->coreContactRepository->updateContact($contactData, $this->contactRepository->getContactId());
        }

        return null;
    }

    /**
     * @param Address $address
     * @return null|Contact
     * @throws \Plenty\Exceptions\ValidationException
     */
    private function updateContactWithAddressData(Address $address)
    {
        $contactData = [];
        $contact = null;

        $contactData['gender'] = $address->gender;
        $contactData['firstName'] = $address->firstName;
        $contactData['lastName'] = $address->lastName;
        $contactData['birthdayAt'] = $address->birthday;
        $contactData['options'] = $this->getContactOptionsFromAddress($address->options);

        $contact = $this->updateContact($contactData);

        return $contact;
    }

    /**
     * @param array $addressOptions
     * @return array
     */
    private function getContactOptionsFromAddress($addressOptions): array
    {
        $options = [];
        $addressToContactOptionsMap =
            [
                AddressOption::TYPE_TELEPHONE =>
                    [
                        'typeId' => ContactOption::TYPE_PHONE,
                        'subTypeId' => ContactOption::SUBTYPE_PRIVATE
                    ],

                AddressOption::TYPE_EMAIL =>
                    [
                        'typeId' => ContactOption::TYPE_MAIL,
                        'subTypeId' => ContactOption::SUBTYPE_PRIVATE
                    ]


            ];

        foreach ($addressOptions as $key => $addressOption) {
            $mapItem = $addressToContactOptionsMap[$addressOption->typeId];

            if (!empty($mapItem)) {
                $options[] =
                    [
                        'typeId' => $mapItem['typeId'],
                        'subTypeId' => $mapItem['subTypeId'],
                        'priority' => 0,
                        'value' => $addressOption->value
                    ];
            }
        }

        return $options;
    }

    /**
     * @param string $newPassword
     * @param int $contactId
     * @param string $hash
     * @return mixed|Contact|null
     * @throws \Plenty\Exceptions\ValidationException
     * @throws \Throwable
     */
    public function updatePassword($newPassword, $contactId = 0, $hash = '')
    {
        /** @var UserDataHashService $hashService */
        $hashService = pluginApp(UserDataHashService::class);
        $hashData = $hashService->getData($hash, $contactId);

        if ((int)$this->contactRepository->getContactId() <= 0 && !is_null($hashData)) {
            /** @var AuthHelper $authHelper */
            $authHelper = pluginApp(AuthHelper::class);
            $contactRepo = $this->coreContactRepository;

            $contact = $authHelper->processUnguarded(
                function () use ($newPassword, $contactId, $contactRepo) {
                    return $contactRepo->updateContact(
                        [
                            'changeOnlyPassword' => true,
                            'password' => $newPassword
                        ],
                        (int)$contactId
                    );
                }
            );
            $hashService->delete($hash);
        } else {
            $contact = $this->updateContact(
                [
                    'changeOnlyPassword' => true,
                    'password' => $newPassword
                ]
            );
        }

        if ($contact instanceof Contact && $contact->id > 0) {
            /** @var WebstoreConfigurationRepositoryContract $webstoreConfigurationRepository */
            $webstoreConfigurationRepository = pluginApp(WebstoreConfigurationRepositoryContract::class);

            /** @var WebstoreConfiguration $webstoreConfiguration */
            $webstoreConfiguration = $webstoreConfigurationRepository->findByPlentyId($contact->plentyId);

            $params = [
                'contactId' => $contact->id,
                'clientId' => $webstoreConfiguration->webstoreId,
                'language' => Utils::getLang()
            ];

            $this->sendMail(
                AutomaticEmailTemplate::CONTACT_NEW_PASSWORD_CONFIRMATION,
                AutomaticEmailContact::class,
                $params
            );
        }

        return $contact;
    }

    /**
     * List the addresses of a contact
     * @param int $typeId
     * @return array|\Illuminate\Database\Eloquent\Collection
     */
    public function getAddresses($typeId = null)
    {
        if ($this->contactRepository->getContactId() > 0) {
            $addresses = $this->contactAddressRepository->getAddresses($this->contactRepository->getContactId(), $typeId);

            if (count($addresses)) {
                foreach ($addresses as $key => $address) {
                    if (is_null($address->gender)) {
                        $addresses[$key]->gender = 'company';
                    }
                }
            }

            return $addresses;
        } else {
            /** @var BasketService $basketService */
            $basketService = pluginApp(BasketService::class);
            $address = null;

            if ($typeId == AddressType::BILLING && $basketService->getBillingAddressId() > 0) {
                $address = $this->addressRepository->findAddressById($basketService->getBillingAddressId());
            } elseif ($typeId == AddressType::DELIVERY && $basketService->getDeliveryAddressId() > 0) {
                $address = $this->addressRepository->findAddressById($basketService->getDeliveryAddressId());
            }

            if ($address instanceof Address) {
                if (is_null($address->gender)) {
                    $address->gender = 'company';
                }

                return [
                    $address
                ];
            }

            return [];
        }
    }

    /**
     * Get an address by ID
     * @param int $addressId
     * @param int $typeId
     * @return Address
     */
    public function getAddress(int $addressId, int $typeId): Address
    {
        $address = null;

        if ($this->contactRepository->getContactId() > 0) {
            $address = $this->contactAddressRepository->getAddress($addressId, $this->contactRepository->getContactId(), $typeId);
        } else {
            /**
             * @var BasketService $basketService
             */
            $basketService = pluginApp(BasketService::class);

            if ($typeId == AddressType::BILLING) {
                $address = $this->addressRepository->findAddressById(
                    ((int)$addressId > 0 ? $addressId : $basketService->getBillingAddressId())
                );
            } elseif ($typeId == AddressType::DELIVERY) {
                $address = $this->addressRepository->findAddressById(
                    ((int)$addressId > 0 ? $addressId : $basketService->getDeliveryAddressId())
                );
            }
        }

        if (is_null($address->gender)) {
            $address->gender = 'company';
        }

        return $address;
    }

    /**
     * Create an address with the specified address type
     * @param array $addressData
     * @param int $typeId
     * @return Address
     * @throws \Plenty\Exceptions\ValidationException
     * @throws \Throwable
     */
    public function createAddress(array $addressData, int $typeId): Address
    {
        if ((isset($addressData['gender']) && empty($addressData['gender'])) || $addressData['gender'] == 'company') {
            $addressData['gender'] = null;
        }

        AddressValidator::validateOrFail($addressData);

        if (ShippingCountry::getAddressFormat($addressData['countryId']) === ShippingCountry::ADDRESS_FORMAT_EN) {
            $addressData['useAddressLightValidator'] = true;
        }

        if (isset($addressData['stateId']) && empty($addressData['stateId'])) {
            $addressData['stateId'] = null;
        }

        $newAddress = null;
        $contact = $this->contactRepository->getContact();
        if (!is_null($contact)) {
            $addressData['options'] = $this->buildAddressEmailOptions([], false, $addressData);
            $newAddress = $this->contactAddressRepository->createAddress(
                $addressData,
                $this->contactRepository->getContactId(),
                $typeId
            );

            if ($typeId == AddressType::BILLING && isset($addressData['name1']) && strlen($addressData['name1'])) {
                $account = $this->createAccount($this->mapAddressDataToAccount($addressData));
                if (
                    $account instanceof Account
                    && (int)$account->id > 0
                    && count($contact->addresses) === 1
                    && $contact->addresses[0]->id === $newAddress->id) {
                    /** @var TemplateConfigRepositoryContract $templateConfigRepository */
                    $templateConfigRepository = pluginApp(TemplateConfigRepositoryContract::class);
                    $classId = $templateConfigRepository->getInteger('global.default_contact_class_b2b');

                    if (is_null($classId) || (int)$classId <= 0) {
                        $classId = $this->contactRepository->getDefaultContactClassId();
                    }

                    if (!is_null($classId) && (int)$classId > 0) {
                        $this->updateContact(
                            [
                                'classId' => $classId
                            ]
                        );
                    }
                }
            }

            $existingContact = $this->contactRepository->getContact();
            if ($typeId == AddressType::BILLING && !strlen($existingContact->firstName) && !strlen(
                    $existingContact->lastName
                )) {
                $this->updateContactWithAddressData($newAddress);
            }
        } else {
            $addressData['options'] = $this->buildAddressEmailOptions([], true, $addressData);
            $newAddress = $this->addressRepository->createAddress($addressData);
        }

        /** @var BasketService $basketService */
        $basketService = pluginApp(BasketService::class);

        if ($newAddress instanceof Address) {
            if ($typeId == AddressType::BILLING) {
                $basketService->setBillingAddressId($newAddress->id);
            } elseif ($typeId == AddressType::DELIVERY) {
                $basketService->setDeliveryAddressId($newAddress->id);
            }

            if (is_null($newAddress->gender)) {
                $newAddress->gender = 'company';
            }
        }

        return $newAddress;
    }

    /**
     * @param array $options
     * @param bool $isGuest
     * @param array $addressData
     * @return array
     * @throws \Exception
     */
    private function buildAddressEmailOptions(array $options = [], $isGuest = false, $addressData = []): array
    {
        if ($isGuest) {
            /** @var SessionStorageRepositoryContract $sessionStorageRepository */
            $sessionStorageRepository = pluginApp(SessionStorageRepositoryContract::class);
            $email = $sessionStorageRepository->getSessionValue(SessionStorageRepositoryContract::GUEST_EMAIL);

            if (!strlen($email)) {
                throw new \Exception('no guest email address found', 11);
            }
        } else {
            $email = $this->contactRepository->getContact()->email;
        }

        if (strlen($email)) {
            $options[] = [
                'typeId' => AddressOption::TYPE_EMAIL,
                'value' => $email
            ];
        }

        if (count($addressData)) {
            if (isset($addressData['vatNumber'])) {
                $options[] = [
                    'typeId' => AddressOption::TYPE_VAT_NUMBER,
                    'value' => $addressData['vatNumber']
                ];
            }

            if (isset($addressData['birthday'])) {
                $options[] = [
                    'typeId' => AddressOption::TYPE_BIRTHDAY,
                    'value' => $addressData['birthday']
                ];
            }

            if (isset($addressData['title'])) {
                $options[] = [
                    'typeId' => AddressOption::TYPE_TITLE,
                    'value' => $addressData['title']
                ];
            }

            if (isset($addressData['telephone'])) {
                $options[] = [
                    'typeId' => AddressOption::TYPE_TELEPHONE,
                    'value' => $addressData['telephone']
                ];
            }

            if (isset($addressData['contactPerson'])) {
                $options[] = [
                    'typeId' => AddressOption::TYPE_CONTACT_PERSON,
                    'value' => $addressData['contactPerson']
                ];
            }

            if ((strtoupper($addressData['address1']) == 'PACKSTATION' || strtoupper(
                        $addressData['address1']
                    ) == 'POSTFILIALE') && isset($addressData['address2']) && isset($addressData['postNumber'])) {
                $options[] =
                    [
                        'typeId' => AddressOption::TYPE_POST_NUMBER,
                        'value' => $addressData['postNumber']
                    ];
            }
        }

        return $options;
    }

    /**
     * Update an address
     * @param int $addressId
     * @param array $addressData
     * @param int $typeId
     * @return Address
     * @throws \Plenty\Exceptions\ModelNotEditableException
     * @throws \Plenty\Exceptions\ValidationException
     * @throws \Throwable
     */
    public function updateAddress(int $addressId, array $addressData, int $typeId): Address
    {
        if ((isset($addressData['gender']) && empty($addressData['gender'])) || $addressData['gender'] == 'company') {
            $addressData['gender'] = null;
        }

        AddressValidator::validateOrFail($addressData);

        $existingAddress = $this->addressRepository->findAddressById($addressId);
        if (isset($addressData['stateId']) && empty($addressData['stateId'])) {
            $addressData['stateId'] = null;
        }

        if (isset($addressData['checkedAt']) && empty($addressData['checkedAt'])) {
            unset($addressData['checkedAt']);
        }

        if ((int)$this->contactRepository->getContactId() > 0) {
            $addressData['options'] = $this->buildAddressEmailOptions([], false, $addressData);

            if ($typeId == AddressType::BILLING && isset($addressData['name1']) && strlen($addressData['name1'])) {
                $this->createAccount($this->mapAddressDataToAccount($addressData));
            } elseif ($typeId == AddressType::BILLING && (!isset($addressData['name1']) || !strlen(
                        $addressData['name1']
                    ))) {
                $existingAddress = $this->getAddress($addressId, AddressType::BILLING);
                if ($existingAddress instanceof Address && strlen($existingAddress->name1)) {
                    $addressData['name1'] = $existingAddress->name1;
                }
            }

            $newAddress = $this->contactAddressRepository->updateAddress(
                $addressData,
                $addressId,
                $this->contactRepository->getContactId(),
                $typeId
            );

            if ($typeId == AddressType::BILLING) {
                $firstStoredAddress = $this->contactAddressRepository->findContactAddressByTypeId(
                    (int)$this->contactRepository->getContactId(),
                    $typeId,
                    false
                );

                if ($addressId == $firstStoredAddress->id) {
                    $this->updateContactWithAddressData($newAddress);
                }
            }
        } else {
            //case for guests
            $addressData['options'] = $this->buildAddressEmailOptions([], true, $addressData);
            $newAddress = $this->addressRepository->updateAddress($addressData, $addressId);
        }

        /** @var AuthHelper $authHelper */
        $authHelper = pluginApp(AuthHelper::class);
        $event = null;
        $authHelper->processUnguarded(
            function () use ($typeId, $newAddress, &$event) {
                /** @var BasketService $basketService */
                $basketService = pluginApp(BasketService::class);
                if ($typeId == AddressType::BILLING) {
                    $basketService->setBillingAddressId($newAddress->id);
                    $event = pluginApp(
                        FrontendUpdateInvoiceAddress::class,
                        ["accountAddressId" => $newAddress->id]
                    );
                } elseif ($typeId == AddressType::DELIVERY) {
                    $basketService->setDeliveryAddressId($newAddress->id);
                    $event = pluginApp(
                        FrontendUpdateDeliveryAddress::class,
                        [
                            'accountAddressId' => $newAddress->id
                        ]
                    );
                }
            }
        );

        /** @var Dispatcher $pluginEventDispatcher */
        $pluginEventDispatcher = pluginApp(Dispatcher::class);

        $addressDiff = ArrayHelper::compare(
            $existingAddress->toArray(),
            $newAddress->toArray()
        );

        if ($event && $existingAddress->countryId == $newAddress->countryId && count($addressDiff) && !(count(
                    $addressDiff
                ) === 1) && in_array('updatedAt', $addressDiff)) {
            $pluginEventDispatcher->fire($event);
            $pluginEventDispatcher->fire(pluginApp(AfterBasketChanged::class));
        }

        //fire public event
        $pluginEventDispatcher->fire(FrontendCustomerAddressChanged::class);

        if (is_null($newAddress->gender)) {
            $newAddress->gender = 'company';
        }

        return $newAddress;
    }

    /**
     * Delete an address
     * @param int $addressId
     * @param int $typeId
     * @throws \Plenty\Exceptions\ModelNotEditableException
     * @throws \Plenty\Exceptions\ValidationException
     */
    public function deleteAddress(int $addressId, int $typeId = 0): void
    {
        /** @var BasketService $basketService */
        $basketService = pluginApp(BasketService::class);

        if ($this->contactRepository->getContactId() > 0) {
            $firstStoredAddress = $this->contactAddressRepository->findContactAddressByTypeId(
                (int)$this->contactRepository->getContactId(),
                $typeId,
                false
            );

            $this->contactAddressRepository->deleteAddress($addressId, $this->contactRepository->getContactId(), $typeId);

            if ($typeId == AddressType::BILLING) {
                $basketService->setBillingAddressId(0);
            } elseif ($typeId == AddressType::DELIVERY) {
                $basketService->setDeliveryAddressId(CustomerAddressResource::ADDRESS_NOT_SET);
            }

            if ($firstStoredAddress instanceof Address && $firstStoredAddress->id === $addressId) {
                $firstStoredAddress = $this->contactAddressRepository->findContactAddressByTypeId(
                    (int)$this->contactRepository->getContactId(),
                    $typeId,
                    false
                );

                if ($firstStoredAddress instanceof Address) {
                    $this->updateContactWithAddressData($firstStoredAddress);
                }
            }
        } else {
            $this->addressRepository->deleteAddress($addressId);
            if ($addressId == $basketService->getBillingAddressId()) {
                $basketService->setBillingAddressId(0);
            } elseif ($addressId == $basketService->getDeliveryAddressId()) {
                $basketService->setDeliveryAddressId(CustomerAddressResource::ADDRESS_NOT_SET);
            }
        }
    }

    /**
     * Get a list of orders for the current contact
     * @param int $page
     * @param int $items
     * @param array $filters
     * @return array|\Plenty\Repositories\Models\PaginatedResult
     */
    public function getOrders(int $page = 1, int $items = 10, array $filters = [])
    {
        $orders = [];

        try {
            /** @var OrderService $orderService */
            $orderService = pluginApp(OrderService::class);
            $orders = $orderService->getOrdersForContact(
                $this->contactRepository->getContactId(),
                $page,
                $items,
                $filters,
                true
            );
        } catch (\Exception $e) {
        }

        return $orders;
    }

    /**
     * @return bool
     */
    public function hasReturns(): bool
    {
        $returns = $this->getReturns(1, 1, [], false);
        if (count($returns->getResult())) {
            return true;
        }

        return false;
    }

    /**
     * @param int $page
     * @param int $items
     * @param array $filters
     * @param bool $wrapped
     * @return \Plenty\Repositories\Models\PaginatedResult
     */
    public function getReturns(int $page = 1, int $items = 10, array $filters = [], $wrapped = true)
    {
        /** @var OrderService $orderService */
        $orderService = pluginApp(OrderService::class);

        $filters['orderType'] = OrderType::RETURNS;

        $returnOrders = $orderService->getOrdersForContact(
            $this->contactRepository->getContactId(),
            $page,
            $items,
            $filters,
            $wrapped
        );

        /** @var PropertyNameFilter $propertyNameFilter */
        $propertyNameFilter = pluginApp(PropertyNameFilter::class);

        foreach ($returnOrders->getResult() as $returnOrder) {
            foreach ($returnOrder->order->orderItems as $orderItem) {
                foreach ($orderItem->orderProperties as $orderProperty) {
                    $orderProperty->name = $propertyNameFilter->getPropertyName($orderProperty);
                    if ($orderProperty->type === 'selection') {
                        $orderProperty->selectionValueName = $propertyNameFilter->getPropertySelectionValueName(
                            $orderProperty
                        );
                    }
                }
            }
        }

        return $returnOrders;
    }

    /**
     * Get the last order created by the current contact
     * @return LocalizedOrder
     */
    public function getLatestOrder()
    {
        /** @var OrderService $orderService */
        $orderService = pluginApp(OrderService::class);

        return $orderService->getLatestOrderForContact(
            $this->contactRepository->getContactId()
        );
    }

    public function resetGuestAddresses(): void
    {
        if ($this->contactRepository->getContactId() <= 0) {
            /** @var BasketService $basketService */
            $basketService = pluginApp(BasketService::class);
            $basketService->setBillingAddressId(0);
            $basketService->setDeliveryAddressId(0);

            $this->sessionStorageRepository->setSessionValue(SessionStorageRepositoryContract::GUEST_EMAIL, null);
        }
    }

    /**
     * @return string
     */
    public function getEmail(): string
    {
        $contact = $this->contactRepository->getContact();
        if ($contact instanceof Contact) {
            $email = $contact->email;
        } else {
            $email = $this->sessionStorageRepository->getSessionValue(SessionStorageRepositoryContract::GUEST_EMAIL);
        }

        if (is_null($email)) {
            $email = '';
        }

        return $email;
    }

    /**
     * @param int $contactId
     * @return string|null
     * @throws \Throwable
     */
    public function getContactNumber($contactId)
    {
        /** @var AuthHelper $authHelper */
        $authHelper = pluginApp(AuthHelper::class);
        $contactRepo = $this->coreContactRepository;

        try {
            $contact = $authHelper->processUnguarded(
                function () use ($contactRepo, $contactId) {
                    return $contactRepo->findContactById($contactId);
                }
            );

            return $contact->number;
        } catch (\Exception $exception) {
            return null;
        }
    }

    public function deleteGuestAddresses(): void
    {
        $addressList[AddressType::BILLING] = $this->getAddresses(AddressType::BILLING);
        $addressList[AddressType::DELIVERY] = $this->getAddresses(AddressType::DELIVERY);

        foreach ($addressList as $typeId => $addresses) {
            $addresses = ArrayHelper::toArray($addresses);
            if (is_array($addresses) && count($addresses) > 0) {
                foreach ($addresses as $address) {
                    if (!count($address['contactRelations'])) {
                        $this->deleteAddress($address['id'], $typeId);
                    }
                }
            }
        }
    }

}
