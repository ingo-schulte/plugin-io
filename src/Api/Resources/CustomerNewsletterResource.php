<?php

namespace IO\Api\Resources;

use IO\Services\CustomerNewsletterService;
use Plenty\Plugin\Http\Response;
use Plenty\Plugin\Http\Request;
use IO\Api\ApiResource;
use IO\Api\ApiResponse;
use IO\Api\ResponseCode;
use IO\Constants\LogLevel;
use IO\Helper\ReCaptcha;
use IO\Services\NotificationService;

class CustomerNewsletterResource extends ApiResource
{
    /** @var CustomerNewsletterService */
    private $newsletterService;

    public function __construct(Request $request, ApiResponse $response, CustomerNewsletterService $newsletterService)
    {
        parent::__construct($request, $response);

        $this->newsletterService = $newsletterService;
    }

    /**
     * Add an email to a newsletter
     * @return Response
     */
    public function store(): Response
    {
        // Honeypot check
        if(strlen($this->request->get('honeypot')))
        {
            // We can potentially expand on the honeypot handling with sending reports to spam protect services
            return $this->response->create(['containsHoneypot' => true], ResponseCode::OK);
        }

        if (!ReCaptcha::verify($this->request->get('recaptcha', null))) {
            /**
            * @var NotificationService $notificationService
            */
            $notificationService = pluginApp(NotificationService::class);
            $notificationService->addNotificationCode(LogLevel::ERROR, 13);

            return $this->response->create('', ResponseCode::BAD_REQUEST);
        }

        $email = $this->request->get('email', '');
        $firstName = $this->request->get('firstName', '');
        $lastName = $this->request->get('lastName', '');
        $emailFolder = $this->request->get('emailFolder', 0);

        $this->newsletterService->saveNewsletterData($email, $emailFolder, $firstName, $lastName);

        return $this->response->create($email, ResponseCode::OK);
    }

    /**
     * Remove an email from the newsletter
     * @param string $selector the email to be removed
     * @return Response
     * @throws \Throwable
     */
    public function destroy(string $selector): Response
    {
        // Honeypot check
        if(strlen($this->request->get('honeypot')))
        {
            return $this->response->create(true, ResponseCode::OK);
        }

        $emailFolder = $this->request->get('emailFolder', 0);

        $success = $this->newsletterService->deleteNewsletterDataByEmail($selector, (int) $emailFolder);

        return $this->response->create($success, ResponseCode::OK);
    }
}
