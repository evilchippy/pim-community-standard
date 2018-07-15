<?php

namespace Webkul\ShopifyBundle\Classes\Validators;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Webkul\ShopifyBundle\Classes\ApiClient;
use Symfony\Component\HttpFoundation\Response;

class CredentialValidator extends ConstraintValidator
{
    /**
     * {@inheritdoc}
     */
    public function validate($value, Constraint $constraint)
    {
        if (!$constraint instanceof Credential) {
            throw new UnexpectedTypeException($constraint, __NAMESPACE__.'\Credential');
        }

        $params = $this->context->getRoot(); 
        if(!empty($params['shopUrl']) && !empty($params['apiKey']) && !empty($params['apiPassword'])) {
            $oauthClient = new ApiClient($params['shopUrl'], $params['apiKey'], $params['apiPassword']);
            $response = $oauthClient->request('getOneProduct', [], []);
            $successFlag = !empty($response['code']) && Response::HTTP_OK == $response['code'];
        } elseif(empty($params['shopUrl']) && empty($params['apiKey']) && empty($params['apiPassword'])) {
            /* empty credentials */
            $successFlag = true;
        }

        if (empty($successFlag)) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ value }}', $this->formatValue($value))
                ->setCode(Credential::INVALID_CREDENTIAL_ERROR)
                ->addViolation();
        }
    }
}
