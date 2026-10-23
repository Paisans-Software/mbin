<?php

declare(strict_types=1);

namespace App\Controller\Api\Magazine;

use App\Controller\Api\BaseApi;
use App\DTO\ImageDto;
use App\DTO\MagazineDto;
use App\DTO\MagazineRequestDto;
use App\DTO\MagazineThemeDto;
use App\DTO\MagazineThemeRequestDto;
use App\Entity\Magazine;
use App\Entity\Report;
use App\Exception\MagazineNameInvalidException;
use App\Factory\ReportFactory;
use App\Service\MagazineManager;
use App\Utils\RegPatterns;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Contracts\Service\Attribute\Required;

class MagazineBaseApi extends BaseApi
{
    private readonly ReportFactory $reportFactory;
    protected readonly MagazineManager $manager;

    #[Required]
    public function setReportFactory(ReportFactory $reportFactory)
    {
        $this->reportFactory = $reportFactory;
    }

    #[Required]
    public function setManager(MagazineManager $manager)
    {
        $this->manager = $manager;
    }

    protected function serializeReport(Report $report)
    {
        $response = $this->reportFactory->createResponseDto($report);

        return $response;
    }

    /**
     * Deserialize a magazine from JSON.
     *
     * @param ?MagazineDto $dto The MagazineDto to modify with new values (default: null to create a new MagazineDto)
     *
     * @return MagazineDto A magazine with only certain fields allowed to be modified by the user
     */
    protected function deserializeMagazine(?MagazineDto $dto = null): MagazineDto
    {
        $dto = $dto ?? new MagazineDto();
        $deserialized = $this->serializer->deserialize($this->request->getCurrentRequest()->getContent(), MagazineRequestDto::class, 'json');
        \assert($deserialized instanceof MagazineRequestDto);

        return $deserialized->mergeIntoDto($dto);
    }

    protected function deserializeThemeFromForm(MagazineThemeDto $dto): MagazineThemeDto
    {
        $deserialized = new MagazineThemeRequestDto();
        $deserialized->customCss = $this->request->getCurrentRequest()->get('customCss');
        $deserialized->backgroundImage = $this->request->getCurrentRequest()->get('backgroundImage');

        $dto = $deserialized->mergeIntoDto($dto);

        return $dto;
    }

    protected function createMagazine(?ImageDto $image = null): Magazine
    {
        $dto = $this->deserializeMagazine();

        if ($image) {
            $dto->icon = $image;
        }

        $errors = $this->validator->validate($dto);
        if (\count($errors) > 0) {
            // When the failure is specifically the magazine `name` format/length rule,
            // surface an actionable message that survives production error rendering
            // (see MagazineCreateApi::__invoke, which returns it as an HTTP 400 body).
            $nameError = $this->getMagazineNameError($errors, $dto->name);
            if (null !== $nameError) {
                throw new MagazineNameInvalidException($nameError);
            }

            throw new BadRequestHttpException((string) $errors);
        }

        if (!empty($dto->rules)) {
            throw new BadRequestHttpException($this->translator->trans('magazine_rules_deprecated'));
        }

        // FORK DIVERGENCE — do not offer this upstream: private magazines
        if ($dto->access->isRestricted() && !$this->getUserOrThrow()->isAdmin()) {
            throw new BadRequestHttpException('Only an admin can create a private or hidden magazine.');
        }

        // Rate limit handled elsewhere
        $magazine = $this->manager->create($dto, $this->getUserOrThrow(), rateLimit: false);

        return $magazine;
    }

    /**
     * Build a user-facing, actionable error message when magazine creation failed
     * because of the magazine `name` format/length rule.
     *
     * `$submittedName` is the raw `name` value the client sent. Returns null when the
     * validation failure is not about the `name` format/length (e.g. a blank name, or
     * an unrelated field), so the caller can fall back to the generic error handling.
     */
    private function getMagazineNameError(ConstraintViolationListInterface $errors, ?string $submittedName): ?string
    {
        $tooShort = false;
        $tooLong = false;
        $badFormat = false;

        foreach ($errors as $violation) {
            \assert($violation instanceof ConstraintViolationInterface);
            if ('name' !== $violation->getPropertyPath()) {
                continue;
            }

            $constraint = $violation->getConstraint();
            if ($constraint instanceof Assert\Length) {
                if (Assert\Length::TOO_SHORT_ERROR === $violation->getCode()) {
                    $tooShort = true;
                } elseif (Assert\Length::TOO_LONG_ERROR === $violation->getCode()) {
                    $tooLong = true;
                }
            } elseif ($constraint instanceof Assert\Regex) {
                $badFormat = true;
            }
        }

        // Too-long names also trip the regex (max length 25); report the length issue first.
        if ($tooLong) {
            return 'Community name must be no more than '.MagazineDto::MAX_NAME_LENGTH.' characters.';
        }

        // Too-short names also trip the regex (min length 2); report the length issue first.
        if ($tooShort) {
            return 'Community name must be at least 2 characters.';
        }

        if (!$badFormat) {
            return null;
        }

        $message = 'No spaces or hyphens allowed. Use only letters, numbers, and underscores (2–25 characters).';

        $suggestion = $this->suggestMagazineName($submittedName);
        if (null !== $suggestion) {
            return $message.' Try: '.$suggestion.'.';
        }

        return $message.' Example: community_name.';
    }

    /**
     * Derive a valid magazine identifier from the submitted value using a simple,
     * predictable transformation: spaces and hyphens become underscores, repeated
     * underscores are collapsed, and leading/trailing underscores are trimmed.
     *
     * Returns null when no transformation was needed, or when the result would
     * itself still be invalid (e.g. the value contains other punctuation). The
     * submitted name is never mutated or used to create the magazine; this is
     * guidance only.
     */
    private function suggestMagazineName(?string $submittedName): ?string
    {
        if (null === $submittedName || '' === $submittedName) {
            return null;
        }

        $candidate = preg_replace('/[\s\-]+/', '_', $submittedName) ?? '';
        $candidate = preg_replace('/_+/', '_', $candidate) ?? '';
        $candidate = trim($candidate, '_');

        if ($candidate === $submittedName || '' === $candidate) {
            return null;
        }

        if (1 !== preg_match(RegPatterns::MAGAZINE_NAME, $candidate)) {
            return null;
        }

        return $candidate;
    }
}
