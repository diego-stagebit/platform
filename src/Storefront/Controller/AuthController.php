<?php declare(strict_types=1);

namespace Shopware\Storefront\Controller;

use Shopware\Core\Checkout\Customer\Exception\BadCredentialsException;
use Shopware\Core\Checkout\Customer\Exception\CustomerAuthThrottledException;
use Shopware\Core\Checkout\Customer\Exception\CustomerNotFoundByHashException;
use Shopware\Core\Checkout\Customer\Exception\CustomerNotFoundByIdException;
use Shopware\Core\Checkout\Customer\Exception\CustomerNotFoundException;
use Shopware\Core\Checkout\Customer\Exception\CustomerOptinNotCompletedException;
use Shopware\Core\Checkout\Customer\Exception\CustomerRecoveryHashExpiredException;
use Shopware\Core\Checkout\Customer\Exception\InvalidImitateCustomerTokenException;
use Shopware\Core\Checkout\Customer\Exception\PasswordPoliciesUpdatedException;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractImitateCustomerRoute;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractLoginRoute;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractLogoutRoute;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractResetPasswordRoute;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractSendPasswordRecoveryMailRoute;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\Feature;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\RateLimiter\Exception\RateLimitExceededException;
use Shopware\Core\Framework\Routing\RoutingException;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Framework\Validation\Exception\ConstraintViolationException;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Checkout\Cart\SalesChannel\StorefrontCartFacade;
use Shopware\Storefront\Framework\Routing\RequestTransformer;
use Shopware\Storefront\Page\Account\Login\AccountGuestLoginPageLoadedHook;
use Shopware\Storefront\Page\Account\Login\AccountLoginPageLoadedHook;
use Shopware\Storefront\Page\Account\Login\AccountLoginPageLoader;
use Shopware\Storefront\Page\Account\RecoverPassword\AccountRecoverPasswordPageLoadedHook;
use Shopware\Storefront\Page\Account\RecoverPassword\AccountRecoverPasswordPageLoader;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * @internal
 * Do not use direct or indirect repository calls in a controller. Always use a store-api route to get or put data
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
#[Package('framework')]
class AuthController extends StorefrontController
{
    /**
     * @internal
     */
    public function __construct(
        private readonly AccountLoginPageLoader $loginPageLoader,
        private readonly AbstractSendPasswordRecoveryMailRoute $sendPasswordRecoveryMailRoute,
        private readonly AbstractResetPasswordRoute $resetPasswordRoute,
        private readonly AbstractLoginRoute $loginRoute,
        private readonly AbstractLogoutRoute $logoutRoute,
        private readonly AbstractImitateCustomerRoute $imitateCustomerRoute,
        private readonly StorefrontCartFacade $cartFacade,
        private readonly AccountRecoverPasswordPageLoader $recoverPasswordPageLoader
    ) {
    }

    #[Route(path: '/account/login', name: 'frontend.account.login.page', defaults: ['_noStore' => true], methods: ['GET'])]
    public function loginPage(Request $request, RequestDataBag $data, SalesChannelContext $context): Response
    {
        // Add '_httpCache' => true, to defaults in Route and remove _noStore
        if (Feature::isActive('PERFORMANCE_TWEAKS') || Feature::isActive('v6.8.0.0')) {
            $request->attributes->set(PlatformRequest::ATTRIBUTE_HTTP_CACHE, true);
            $request->attributes->remove(PlatformRequest::ATTRIBUTE_NO_STORE);
        }

        $customer = $context->getCustomer();

        /** @var string $redirect */
        $redirect = $request->get('redirectTo', $customer?->getGuest() ? 'frontend.account.logout.page' : 'frontend.account.home.page');

        if ($customer !== null) {
            $request->request->set('redirectTo', $redirect);

            return $this->createActionResponse($request);
        }

        $page = $this->loginPageLoader->load($request, $context);

        $this->hook(new AccountLoginPageLoadedHook($page, $context));

        return $this->renderStorefront('@Storefront/storefront/page/account/register/index.html.twig', [
            'redirectTo' => $redirect,
            'redirectParameters' => $request->get('redirectParameters', json_encode([])),
            'errorRoute' => $request->attributes->get('_route'),
            'page' => $page,
            'loginError' => (bool) $request->get('loginError'),
            'waitTime' => $request->get('waitTime'),
            'errorSnippet' => $request->get('errorSnippet'),
            'data' => $data,
        ]);
    }

    #[Route(path: '/account/guest/login', name: 'frontend.account.guest.login.page', defaults: ['_noStore' => true], methods: ['GET'])]
    public function guestLoginPage(Request $request, SalesChannelContext $context): Response
    {
        /** @var string|null $redirect */
        $redirect = $request->get('redirectTo');
        if (!$redirect) {
            // page was probably called directly
            $this->addFlash(self::DANGER, $this->trans('account.orderGuestLoginWrongCredentials'));

            return $this->redirectToRoute('frontend.account.login.page');
        }

        $customer = $context->getCustomer();

        if ($customer !== null) {
            $request->request->set('redirectTo', $redirect);

            return $this->createActionResponse($request);
        }

        $waitTime = (int) $request->get('waitTime');
        if ($waitTime) {
            $this->addFlash(self::INFO, $this->trans('account.loginThrottled', ['%seconds%' => $waitTime]));
        }

        if ((bool) $request->get('loginError')) {
            $this->addFlash(self::DANGER, $this->trans('account.orderGuestLoginWrongCredentials'));
        }

        $page = $this->loginPageLoader->load($request, $context);

        $this->hook(new AccountGuestLoginPageLoadedHook($page, $context));

        return $this->renderStorefront('@Storefront/storefront/page/account/guest-auth.html.twig', [
            'redirectTo' => $redirect,
            'redirectParameters' => $request->get('redirectParameters', json_encode([])),
            'page' => $page,
        ]);
    }

    #[Route(path: '/account/logout', name: 'frontend.account.logout.page', methods: ['GET'])]
    public function logout(Request $request, SalesChannelContext $context, RequestDataBag $dataBag): Response
    {
        if ($context->getCustomer() === null) {
            return $this->redirectToRoute('frontend.account.login.page');
        }

        try {
            $this->logoutRoute->logout($context, $dataBag);
            $this->addFlash(self::SUCCESS, $this->trans('account.logoutSucceeded'));

            $parameters = [];
        } catch (ConstraintViolationException $formViolations) {
            $parameters = ['formViolations' => $formViolations];
        }

        return $this->redirectToRoute('frontend.account.login.page', $parameters);
    }

    #[Route(path: '/account/login', name: 'frontend.account.login', defaults: ['XmlHttpRequest' => true], methods: ['POST'])]
    public function login(Request $request, RequestDataBag $data, SalesChannelContext $context): Response
    {
        $customer = $context->getCustomer();

        if ($customer !== null && $customer->getGuest() === false) {
            return $this->createActionResponse($request);
        }

        try {
            $token = $this->loginRoute->login($data, $context)->getToken();
            $cartBeforeNewContext = $this->cartFacade->get($token, $context);

            if (!empty($token)) {
                $this->addCartErrors($cartBeforeNewContext);

                return $this->createActionResponse($request);
            }
        } catch (CustomerOptinNotCompletedException $e) {
            $errorSnippet = $e->getSnippetKey();
        } catch (CustomerAuthThrottledException $e) {
            $waitTime = $e->getWaitTime();
        } catch (BadCredentialsException|CustomerNotFoundException) {
        } catch (PasswordPoliciesUpdatedException $e) {
            $this->addFlash(self::WARNING, $this->trans('account.passwordPoliciesUpdated'));

            return $this->forwardToRoute('frontend.account.recover.page');
        } finally {
            $data->set('password', null);
        }

        return $this->forwardToRoute(
            'frontend.account.login.page',
            [
                'loginError' => true,
                'errorSnippet' => $errorSnippet ?? null,
                'waitTime' => $waitTime ?? null,
            ]
        );
    }

    #[Route(path: '/account/recover', name: 'frontend.account.recover.page', methods: ['GET'])]
    public function recoverAccountForm(Request $request, SalesChannelContext $context): Response
    {
        // Add '_httpCache' => true, to defaults in Route
        if (Feature::isActive('PERFORMANCE_TWEAKS') || Feature::isActive('v6.8.0.0')) {
            $request->attributes->set(PlatformRequest::ATTRIBUTE_HTTP_CACHE, true);
            $request->attributes->remove(PlatformRequest::ATTRIBUTE_NO_STORE);
        }

        $page = $this->loginPageLoader->load($request, $context);

        return $this->renderStorefront('@Storefront/storefront/page/account/profile/recover-password.html.twig', [
            'page' => $page,
        ]);
    }

    #[Route(path: '/account/recover', name: 'frontend.account.recover.request', methods: ['POST'])]
    public function generateAccountRecovery(Request $request, RequestDataBag $data, SalesChannelContext $context): Response
    {
        try {
            $mailData = $data->get('email');
            if (!$mailData instanceof DataBag) {
                throw RoutingException::invalidRequestParameter('email');
            }
            $mailData->set('storefrontUrl', $request->attributes->get(RequestTransformer::STOREFRONT_URL));

            $this->sendPasswordRecoveryMailRoute->sendRecoveryMail(
                $mailData->toRequestDataBag(),
                $context,
                false
            );

            $this->addFlash(self::SUCCESS, $this->trans('account.recoveryMailSend'));
        } catch (CustomerNotFoundException $e) {
            $this->addFlash(self::SUCCESS, $this->trans('account.recoveryMailSend'));
        } catch (InconsistentCriteriaIdsException $e) {
            $this->addFlash(self::DANGER, $this->trans('error.message-default'));
        } catch (RateLimitExceededException $e) {
            $this->addFlash(self::INFO, $this->trans('error.rateLimitExceeded', ['%seconds%' => $e->getWaitTime()]));
        } catch (ConstraintViolationException $formViolations) {
            return $this->forwardToRoute(
                'frontend.account.recover.page',
                ['formViolations' => $formViolations]
            );
        }

        return $this->redirectToRoute('frontend.account.recover.page');
    }

    #[Route(path: '/account/recover/password', name: 'frontend.account.recover.password.page', methods: ['GET'])]
    public function resetPasswordForm(Request $request, SalesChannelContext $context): Response
    {
        /** @var ?string $hash */
        $hash = $request->get('hash');

        if (!$hash || !\is_string($hash)) {
            $this->addFlash(self::DANGER, $this->trans('account.passwordHashNotFound'));

            return $this->redirectToRoute('frontend.account.recover.request');
        }

        try {
            $page = $this->recoverPasswordPageLoader->load($request, $context, $hash);
        } catch (ConstraintViolationException|CustomerNotFoundByHashException) {
            $this->addFlash(self::DANGER, $this->trans('account.passwordHashNotFound'));

            return $this->redirectToRoute('frontend.account.recover.request');
        }

        $this->hook(new AccountRecoverPasswordPageLoadedHook($page, $context));

        if ($page->getHash() === null || $page->isHashExpired()) {
            $this->addFlash(self::DANGER, $this->trans('account.passwordHashNotFound'));

            return $this->redirectToRoute('frontend.account.recover.request');
        }

        return $this->renderStorefront('@Storefront/storefront/page/account/profile/reset-password.html.twig', [
            'page' => $page,
            'formViolations' => $request->get('formViolations'),
        ]);
    }

    #[Route(path: '/account/recover/password', name: 'frontend.account.recover.password.reset', methods: ['POST'])]
    public function resetPassword(RequestDataBag $data, SalesChannelContext $context): Response
    {
        $passwordData = $data->get('password');
        if (!$passwordData instanceof DataBag) {
            throw RoutingException::invalidRequestParameter('password');
        }
        $hash = $passwordData->get('hash');

        try {
            $this->resetPasswordRoute->resetPassword($passwordData->toRequestDataBag(), $context);

            $this->addFlash(self::SUCCESS, $this->trans('account.passwordChangeSuccess'));
        } catch (ConstraintViolationException $formViolations) {
            if ($formViolations->getViolations('newPassword')->count() === 1) {
                $this->addFlash(self::DANGER, $this->trans('account.passwordNotIdentical'));
            } else {
                $this->addFlash(self::DANGER, $this->trans('account.passwordChangeNoSuccess'));
            }

            return $this->forwardToRoute(
                'frontend.account.recover.password.page',
                ['hash' => $hash, 'formViolations' => $formViolations, 'passwordFormViolation' => true]
            );
        } catch (CustomerNotFoundByHashException) {
            $this->addFlash(self::DANGER, $this->trans('account.passwordChangeNoSuccess'));

            return $this->forwardToRoute('frontend.account.recover.request');
        } catch (CustomerRecoveryHashExpiredException) {
            $this->addFlash(self::DANGER, $this->trans('account.passwordHashExpired'));

            return $this->forwardToRoute('frontend.account.recover.request');
        }

        return $this->redirectToRoute('frontend.account.profile.page');
    }

    #[Route(path: '/account/login/imitate-customer', name: 'frontend.account.login.imitate-customer', methods: ['POST'])]
    public function imitateCustomerLogin(RequestDataBag $data, SalesChannelContext $context): Response
    {
        try {
            $this->imitateCustomerRoute->imitateCustomerLogin($data, $context);

            return $this->redirectToRoute('frontend.account.home.page');
        } catch (InvalidImitateCustomerTokenException|CustomerNotFoundByIdException) {
            return $this->forwardToRoute(
                'frontend.account.login.page',
                [
                    'loginError' => true,
                ]
            );
        }
    }
}
