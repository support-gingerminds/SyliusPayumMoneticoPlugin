<?php

declare(strict_types=1);

namespace FluxSE\SyliusPayumMoneticoPlugin\Controller;

use Ekyna\Component\Payum\Monetico\Api\Api;
use Payum\Core\Model\Identity;
use Payum\Core\Payum;
use Payum\Core\Request\Notify;
use Payum\Core\Security\TokenInterface;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentInterface as BasePaymentInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Webmozart\Assert\Assert;

final class NotifyController
{
    /** @var EntityRepository */
    private $paymentRepository;

    /** @var EntityRepository */
    private $paymentSecurityTokenRepository;

    /** @var Payum */
    private $payum;

    public function __construct(
        EntityRepository $paymentRepository,
        EntityRepository $paymentSecurityTokenRepository,
        Payum $payum
    ) {
        $this->paymentRepository = $paymentRepository;
        $this->paymentSecurityTokenRepository = $paymentSecurityTokenRepository;
        $this->payum = $payum;
    }

    public function doAction(Request $request): Response
    {
        // Get the reference you set in your ConvertAction
        $reference = $request->request->get('reference');

        if (null === $reference) {
            throw new NotFoundHttpException();
        }

        if (false === is_string($reference)) {
            throw new NotFoundHttpException();
        }

        $queryBuilder = $this->paymentRepository->createQueryBuilder('p');
        // Find your payment entity
        /** @var PaymentInterface[] $payments */
        $payments = $queryBuilder
            ->join('p.method', 'm')
            ->join('m.gatewayConfig', 'gc')
            ->where('p.details LIKE :reference')
            ->andWhere('gc.factoryName = :factory_name')
            ->orderBy('p.createdAt', 'DESC')
            ->setParameters([
                'reference' => '%"reference":%"' . $reference . '"%',
                'factory_name' => 'monetico',
            ])
            ->getQuery()->getResult();
        if ([] === $payments) {
            throw new NotFoundHttpException(
                sprintf('Payment not found for this reference : "%s" !', $reference)
            );
        }

        $payment = $this->processPayments($payments);

        if ($request->request->has('modepaiement')) {
            $payment->setPaymentResponseType($request->request->get('modepaiement'));
        }

        if (null === $payment) {
            throw new NotFoundHttpException(
                sprintf(
                    'No payment found with state "%s", "%s" or "%s" for this reference : "%s" !',
                    PaymentInterface::STATE_NEW,
                    PaymentInterface::STATE_CANCELLED,
                    PaymentInterface::STATE_FAILED,
                    $reference
                )
            );
        }

        // The Payum `Token` will be updated with the reference to the new Sylius `Payment`
        // to avoid customer to be redirected to the capture URL with an old `canceled` `Payment`
        $this->updatePaymentSecurityToken($payment);

        $this->notifyWithPayment($payment);

        // We don't invalidate payment tokens because if the customer click on go back to the store
        // the token won't exist anymore so there will be a 404 error page

        // Return expected response
        return new Response(Api::NOTIFY_SUCCESS);
    }

    /**
     * @param PaymentInterface[] $payments
     */
    private function processPayments(array $payments): ?PaymentInterface
    {
        foreach ($payments as $payment) {
            switch ($payment->getState()) {
                case BasePaymentInterface::STATE_NEW:
                case BasePaymentInterface::STATE_CART:
                    return $payment;
                case BasePaymentInterface::STATE_CANCELLED:
                case BasePaymentInterface::STATE_FAILED:
                    $order = $payment->getOrder();
                    Assert::notNull($order);

                    // Retrieve this new payment if it exists
                    $emptyPayment = $order->getLastPayment(BasePaymentInterface::STATE_NEW);

                    if (null === $emptyPayment) {
                        $emptyPayment = $order->getLastPayment(BasePaymentInterface::STATE_CART);
                    }

                    Assert::notNull($emptyPayment, sprintf(
                        'No Payment state "%s" found into the order !',
                        BasePaymentInterface::STATE_NEW . ',' . BasePaymentInterface::STATE_CART
                    ));

                    // Add the previous details to allow `PaymentResponseAction` to update the values
                    $emptyPayment->setDetails($payment->getDetails());

                    return $emptyPayment;
            }
        }

        return null;
    }

    private function notifyWithPayment(PaymentInterface $payment): void
    {
        /** @var PaymentMethodInterface|null $paymentMethod */
        $paymentMethod = $payment->getMethod();
        Assert::notNull($paymentMethod);
        $gatewayConfig = $paymentMethod->getGatewayConfig();
        Assert::notNull($gatewayConfig);

        $gatewayName = $gatewayConfig->getGatewayName();

        // Execute notify & status actions.
        $gateway = $this->payum->getGateway($gatewayName);
        $gateway->execute(new Notify($payment));
    }

    private function updatePaymentSecurityToken(PaymentInterface $newPayment): void
    {
        $order = $newPayment->getOrder();
        Assert::notNull($order);

        foreach ($order->getPayments() as $payment) {
            $identify = new Identity($payment->getId(), get_class($payment));
            /** @var TokenInterface[] $tokens */
            $tokens = $this->paymentSecurityTokenRepository->findBy([
                'details' => $identify,
            ]);
            if (count($tokens) === 0) {
                continue;
            }

            $newIdentify = new Identity($newPayment->getId(), get_class($newPayment));
            foreach ($tokens as $token) {
                $token->setDetails($newIdentify);
            }
        }
    }
}
