<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Security;

use App\Tests\WebTestCase;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

class ResetPasswordControllerTest extends WebTestCase
{
    public function testPasswordUserReceivesResetEmail(): void
    {
        $this->getUserByUsername('JohnDoe');

        $this->requestReset('JohnDoe@example.com');

        $this->assertResponseRedirects('/reset-password/check-email');
        $this->assertEmailCount(1);

        /** @var TemplatedEmail $email */
        $email = $this->getMailerMessage();

        $this->assertEmailHeaderSame($email, 'To', 'JohnDoe@example.com');
    }

    public function testSsoControlledUserReceivesNoResetEmail(): void
    {
        $user = $this->getUserByUsername('JohnDoe');
        $user->oauthGoogleId = 'google-subject';
        $this->entityManager->flush();

        $this->requestReset('nobody@example.com');
        $this->assertEmailCount(0);
        $unknownStatus = $this->client->getResponse()->getStatusCode();
        $unknownLocation = $this->client->getResponse()->headers->get('Location');

        $this->requestReset('JohnDoe@example.com');
        $this->assertEmailCount(0);

        $this->assertResponseStatusCodeSame($unknownStatus);
        $this->assertResponseHeaderSame('Location', $unknownLocation);
    }

    private function requestReset(string $email): void
    {
        $crawler = $this->client->request('GET', '/reset-password');

        $this->client->submit(
            $crawler->filter('form[name=reset_password_request_form]')->selectButton('Reset password')->form([
                'reset_password_request_form[email]' => $email,
            ])
        );
    }
}
