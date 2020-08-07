<?php

declare(strict_types=1);

/*
 * This file is part of the Sonata Project package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\AdminBundle\Tests\Admin\Extension;

use PHPUnit\Framework\TestCase;
use Prophecy\Prophecy\ObjectProphecy;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Admin\Extension\LockExtension;
use Sonata\AdminBundle\Builder\FormContractorInterface;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Model\LockInterface;
use Sonata\AdminBundle\Model\ModelManagerInterface;
use Sonata\AdminBundle\Tests\App\Model\ModelManager;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilder;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;

class LockExtensionTest extends TestCase
{
    /**
     * @var LockExtension
     */
    private $lockExtension;

    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * @var AdminInterface
     */
    private $admin;

    /**
     * @var LockInterface
     */
    private $modelManager;

    /**
     * @var stdClass
     */
    private $object;

    /**
     * @var Request
     */
    private $request;

    protected function setUp(): void
    {
        $this->modelManager = $this->prophesize(ModelManager::class);
        $this->admin = $this->prophesize(AbstractAdmin::class);
        $this->eventDispatcher = new EventDispatcher();
        $this->request = new Request();
        $this->object = new \stdClass();
        $this->lockExtension = new LockExtension();
    }

    public function testModelManagerImplementsLockInterface(): void
    {
        $this->assertInstanceOf(LockInterface::class, $this->modelManager->reveal());
    }

    public function testConfigureFormFields(): void
    {
        $formMapper = $this->configureFormMapper();
        $form = $this->configureForm();
        $this->configureAdmin(null, null, $this->modelManager->reveal());
        $event = new FormEvent($form->reveal(), $this->object);

        $this->modelManager->getLockVersion($this->object)->willReturn(1);

        $form->add(
            '_lock_version',
            HiddenType::class,
            ['mapped' => false, 'data' => 1]
        )->shouldBeCalled();

        $this->lockExtension->configureFormFields($formMapper);
        $this->eventDispatcher->dispatch($event, FormEvents::PRE_SET_DATA);
    }

    public function testConfigureFormFieldsWhenModelManagerIsNotImplementingLockerInterface(): void
    {
        $modelManager = $this->prophesize(ModelManagerInterface::class);
        $formMapper = $this->configureFormMapper();
        $form = $this->configureForm();
        $this->configureAdmin(null, null, $modelManager->reveal());
        $event = new FormEvent($form->reveal(), []);

        $form->add()->shouldNotBeCalled();

        $this->lockExtension->configureFormFields($formMapper);
        $this->eventDispatcher->dispatch($event, FormEvents::PRE_SET_DATA);
    }

    public function testConfigureFormFieldsWhenFormEventHasNoData(): void
    {
        $formMapper = $this->configureFormMapper();
        $form = $this->configureForm();
        $event = new FormEvent($form->reveal(), null);

        $form->add()->shouldNotBeCalled();

        $this->lockExtension->configureFormFields($formMapper);
        $this->eventDispatcher->dispatch($event, FormEvents::PRE_SET_DATA);
    }

    public function testConfigureFormFieldsWhenFormHasParent(): void
    {
        $formMapper = $this->configureFormMapper();
        $form = $this->configureForm();
        $event = new FormEvent($form->reveal(), []);

        $form->getParent()->willReturn('parent');
        $form->add()->shouldNotBeCalled();

        $this->lockExtension->configureFormFields($formMapper);
        $this->eventDispatcher->dispatch($event, FormEvents::PRE_SET_DATA);
    }

    public function testConfigureFormFieldsWhenModelManagerHasNoLockedVersion(): void
    {
        $formMapper = $this->configureFormMapper();
        $form = $this->configureForm();
        $this->configureAdmin(null, null, $this->modelManager->reveal());
        $event = new FormEvent($form->reveal(), $this->object);

        $this->modelManager->getLockVersion($this->object)->willReturn(null);
        $form->add()->shouldNotBeCalled();

        $this->lockExtension->configureFormFields($formMapper);
        $this->eventDispatcher->dispatch($event, FormEvents::PRE_SET_DATA);
    }

    public function testPreUpdateIfAdminHasNoRequest(): void
    {
        $this->configureAdmin();
        $this->modelManager->lock()->shouldNotBeCalled();

        $this->lockExtension->preUpdate($this->admin->reveal(), $this->object);
    }

    public function testPreUpdateIfObjectIsNotVersioned(): void
    {
        $this->configureAdmin();
        $this->modelManager->lock()->shouldNotBeCalled();

        $this->lockExtension->preUpdate($this->admin->reveal(), $this->object);
    }

    public function testPreUpdateIfRequestDoesNotHaveLockVersion(): void
    {
        $uniqid = 'admin123';
        $this->configureAdmin($uniqid, $this->request);

        $this->modelManager->lock()->shouldNotBeCalled();

        $this->request->request->set($uniqid, ['something']);
        $this->lockExtension->preUpdate($this->admin->reveal(), $this->object);
    }

    public function testPreUpdateIfModelManagerIsNotImplementingLockerInterface(): void
    {
        $modelManager = $this->prophesize(ModelManagerInterface::class);
        $uniqid = 'admin123';
        $this->configureAdmin($uniqid, $this->request, $modelManager->reveal());
        $this->modelManager->lock()->shouldNotBeCalled();

        $this->request->request->set($uniqid, ['_lock_version' => 1]);
        $this->lockExtension->preUpdate($this->admin->reveal(), $this->object);
    }

    public function testPreUpdateIfObjectIsVersioned(): void
    {
        $uniqid = 'admin123';
        $this->configureAdmin($uniqid, $this->request, $this->modelManager->reveal());

        $this->modelManager->lock($this->object, 1)->shouldBeCalled();

        $this->request->request->set($uniqid, ['_lock_version' => 1]);
        $this->lockExtension->preUpdate($this->admin->reveal(), $this->object);
    }

    private function configureForm(): ObjectProphecy
    {
        $form = $this->prophesize(FormInterface::class);

        $form->getData()->willReturn($this->object);
        $form->getParent()->willReturn(null);

        return $form;
    }

    private function configureFormMapper(): FormMapper
    {
        $contractor = $this->prophesize(FormContractorInterface::class);
        $formFactory = $this->prophesize(FormFactoryInterface::class);
        $formBuilder = new FormBuilder('form', null, $this->eventDispatcher, $formFactory->reveal());

        return new FormMapper($contractor->reveal(), $formBuilder, $this->admin->reveal());
    }

    private function configureAdmin(
        ?string $uniqid = null,
        ?Request $request = null,
        ?ModelManagerInterface $modelManager = null
    ): void {
        $this->admin->getUniqid()->willReturn($uniqid);
        $this->admin->getRequest()->willReturn($request);
        $this->admin->hasRequest()->willReturn(null !== $request);
        $this->admin->getModelManager()->willReturn($modelManager);
    }
}
