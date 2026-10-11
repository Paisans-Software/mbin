<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Security;

use App\Tests\WebTestCase;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DomCrawler\Crawler;

class ResetPasswordFlowTest extends WebTestCase
{
    public function testUserCanResetPassword(): void
    {
        $this->getUserByUsername('JohnDoe');

        $crawler = $this->client->request('GET', '/reset-password');
        $this->assertResponseIsSuccessful();

        $this->client->submit(
            $crawler->filter('form[name=reset_password_request_form]')->selectButton('Reset password')->form([
                'reset_password_request_form[email]' => 'JohnDoe@example.com',
            ])
        );

        $this->assertEmailCount(1);

        /** @var TemplatedEmail $email */
        $email = $this->getMailerMessage();
        $resetLink = (new Crawler($email->getHtmlBody()))
            ->filter('a.btn.btn__primary')
            ->attr('href')
        ;

        $this->client->request('GET', $resetLink);
        $crawler = $this->client->followRedirect();
        $this->assertResponseIsSuccessful();

        $this->client->submit(
            $crawler->filter('form[name=change_password_form]')->selectButton('Reset password')->form([
                'change_password_form[plainPassword][first]' => 'newsecret',
                'change_password_form[plainPassword][second]' => 'newsecret',
            ])
        );

        $crawler = $this->client->followRedirect();

        $this->client->submit(
            $crawler->filter('#main form')->selectButton('Log in')->form([
                'email' => 'JohnDoe',
                'password' => 'newsecret',
            ])
        );

        $this->client->followRedirect();

        $this->assertSelectorTextContains('#header', 'JohnDoe');
    }
}
