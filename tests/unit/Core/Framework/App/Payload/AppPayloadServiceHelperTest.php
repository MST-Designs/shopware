<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\App\Payload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Api\Serializer\JsonEntityEncoder;
use Shopware\Core\Framework\App\AppEntity;
use Shopware\Core\Framework\App\AppException;
use Shopware\Core\Framework\App\Hmac\Guzzle\AuthMiddleware;
use Shopware\Core\Framework\App\Payload\AppPayloadServiceHelper;
use Shopware\Core\Framework\App\Payload\Source;
use Shopware\Core\Framework\App\Payload\SourcedPayloadInterface;
use Shopware\Core\Framework\App\ShopId\ShopIdProvider;
use Shopware\Core\Framework\App\TaxProvider\Payload\TaxProviderPayload;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\Store\InAppPurchase;
use Shopware\Core\Framework\Struct\Serializer\StructNormalizer;
use Shopware\Core\Framework\Test\IdsCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\TaxProvider\TaxProviderDefinition;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Serializer;

/**
 * @internal
 */
#[CoversClass(AppPayloadServiceHelper::class)]
class AppPayloadServiceHelperTest extends TestCase
{
    private IdsCollection $ids;

    protected function setUp(): void
    {
        $this->ids = new IdsCollection();
    }

    public function testBuildSource(): void
    {
        InAppPurchase::registerPurchases([
            'purchase-1' => $this->ids->get('app'),
            'purchase-2' => $this->ids->get('app'),
            'purchase-3' => $this->ids->get('another-app'),
        ]);

        $shopIdProvider = $this->createMock(ShopIdProvider::class);
        $shopIdProvider
            ->method('getShopId')
            ->willReturn($this->ids->get('shop-id'));

        $appPayloadServiceHelper = new AppPayloadServiceHelper(
            $this->createMock(DefinitionInstanceRegistry::class),
            $this->createMock(JsonEntityEncoder::class),
            $shopIdProvider,
            'https://shopware.com'
        );

        $app = new AppEntity();
        $app->setId($this->ids->get('app'));
        $app->setVersion('1.0.0');

        $source = $appPayloadServiceHelper->buildSource($app);

        static::assertSame('https://shopware.com', $source->getUrl());
        static::assertSame($this->ids->get('shop-id'), $source->getShopId());
        static::assertSame('1.0.0', $source->getAppVersion());
        static::assertSame(['purchase-1', 'purchase-2'], $source->getInAppPurchases());
        InAppPurchase::reset();
    }

    public function testEncode(): void
    {
        $context = new Context(new SystemSource());
        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext
            ->method('getContext')
            ->willReturn($context);

        $cart = new Cart($this->ids->get('cart'));
        $source = new Source('https://shopware.com', $this->ids->get('shop-id'), '1.0.0');
        $payload = new TaxProviderPayload($cart, $salesChannelContext);
        $payload->setSource($source);

        $definitionInstanceRegistry = $this->createMock(DefinitionInstanceRegistry::class);
        $definitionInstanceRegistry
            ->method('getByEntityClass')
            ->willReturn(new TaxProviderDefinition());

        $entityEncoder = new JsonEntityEncoder(
            new Serializer([new StructNormalizer()], [new JsonEncoder()])
        );

        $appPayloadServiceHelper = new AppPayloadServiceHelper(
            $definitionInstanceRegistry,
            $entityEncoder,
            $this->createMock(ShopIdProvider::class),
            'https://shopware.com'
        );

        $array = $appPayloadServiceHelper->encode($payload);

        static::assertSame(['source' => $source, 'cart' => $cart, 'context' => []], $array);
    }

    public function testCreateRequestOptionsWithNoParams(): void
    {
        $context = Context::createDefaultContext();
        $definitionInstanceRegistry = $this->createMock(DefinitionInstanceRegistry::class);
        $entityEncoder = $this->createMock(JsonEntityEncoder::class);
        $shopIdProvider = $this->createMock(ShopIdProvider::class);
        $shopIdProvider
            ->method('getShopId')
            ->willReturn($this->ids->get('shop-id'));

        $appPayloadServiceHelper = new AppPayloadServiceHelper(
            $definitionInstanceRegistry,
            $entityEncoder,
            $shopIdProvider,
            'https://shopware.com'
        );

        $app = new AppEntity();
        $app->setId($this->ids->get('app'));
        $app->setVersion('1.0.0');
        $app->setAppSecret('top-secret');

        $payload = $this->createMock(SourcedPayloadInterface::class);
        $payload
            ->expects(static::once())
            ->method('setSource')
            ->with(static::isInstanceOf(Source::class));
        $payload
            ->method('jsonSerialize')
            ->willReturn(['key' => 'value']);

        $jsonPayload = $appPayloadServiceHelper->createRequestOptions($payload, $app, $context);

        static::assertEquals($context, $jsonPayload[AuthMiddleware::APP_REQUEST_CONTEXT]);
        static::assertEquals('top-secret', $jsonPayload[AuthMiddleware::APP_REQUEST_TYPE][AuthMiddleware::APP_SECRET]);
        static::assertEquals(['Content-Type' => 'application/json'], $jsonPayload['headers']);
        static::assertJsonStringEqualsJsonString('{"key":"value"}', $jsonPayload['body']);
    }

    public function testCreateRequestOptionsWithAdditionalParams(): void
    {
        $context = Context::createDefaultContext();
        $definitionInstanceRegistry = $this->createMock(DefinitionInstanceRegistry::class);
        $entityEncoder = $this->createMock(JsonEntityEncoder::class);
        $shopIdProvider = $this->createMock(ShopIdProvider::class);
        $shopIdProvider
            ->method('getShopId')
            ->willReturn($this->ids->get('shop-id'));

        $appPayloadServiceHelper = new AppPayloadServiceHelper(
            $definitionInstanceRegistry,
            $entityEncoder,
            $shopIdProvider,
            'https://shopware.com'
        );

        $app = new AppEntity();
        $app->setId($this->ids->get('app'));
        $app->setVersion('1.0.0');
        $app->setAppSecret('top-secret');

        $payload = $this->createMock(SourcedPayloadInterface::class);
        $payload
            ->expects(static::once())
            ->method('setSource')
            ->with(static::isInstanceOf(Source::class));
        $payload
            ->method('jsonSerialize')
            ->willReturn(['key' => 'value']);

        $jsonPayload = $appPayloadServiceHelper->createRequestOptions($payload, $app, $context, ['timeout' => 50]);

        static::assertEquals($context, $jsonPayload[AuthMiddleware::APP_REQUEST_CONTEXT]);
        static::assertEquals('top-secret', $jsonPayload[AuthMiddleware::APP_REQUEST_TYPE][AuthMiddleware::APP_SECRET]);
        static::assertEquals(['Content-Type' => 'application/json'], $jsonPayload['headers']);
        static::assertArrayHasKey('timeout', $jsonPayload);
        static::assertEquals(50, $jsonPayload['timeout']);
        static::assertJsonStringEqualsJsonString('{"key":"value"}', $jsonPayload['body']);
    }

    public function testCreateRequestOptionsThrowsException(): void
    {
        static::expectException(AppException::class);
        static::expectExceptionMessage('App registration for "TestApp" failed: App secret is missing');

        $context = Context::createDefaultContext();
        $definitionInstanceRegistry = $this->createMock(DefinitionInstanceRegistry::class);
        $entityEncoder = $this->createMock(JsonEntityEncoder::class);
        $shopIdProvider = $this->createMock(ShopIdProvider::class);

        $appPayloadServiceHelper = new AppPayloadServiceHelper(
            $definitionInstanceRegistry,
            $entityEncoder,
            $shopIdProvider,
            'https://shopware.com'
        );

        $app = new AppEntity();
        $app->setId($this->ids->get('app'));
        $app->setVersion('1.0.0');
        $app->setName('TestApp');

        $payload = $this->createMock(SourcedPayloadInterface::class);

        $appPayloadServiceHelper->createRequestOptions($payload, $app, $context);
    }
}
