<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Form;

use App\Entity\Activity;
use App\Entity\Customer;
use App\Form\Type\InvoiceLabelType;
use App\Form\Type\ProjectType;
use App\Form\Type\TeamType;
use App\Repository\ProjectRepository;
use App\Repository\Query\ProjectFormTypeQuery;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

class ActivityEditForm extends AbstractType
{
    use EntityFormTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $project = null;
        $customer = null;
        $isNew = true;
        $isGlobal = false;
        $options['currency'] = null;

        if (isset($options['data'])) {
            /** @var Activity $entry */
            $entry = $options['data'];
            $isGlobal = $entry->isGlobal();

            if (!$isGlobal) {
                $project = $entry->getProject();
                $customer = $project->getCustomer();
                $options['currency'] = $customer->getCurrency();
            }

            $isNew = $entry->getId() === null;
        }

        $builder
            ->add('name', TextType::class, [
                'label' => 'name',
                'attr' => [
                    'autofocus' => 'autofocus'
                ],
            ])
            ->add('number', TextType::class, [
                'label' => 'activity_number',
                'required' => false,
                'attr' => [
                    'maxlength' => 10,
                ],
            ])
            ->add('comment', TextareaType::class, [
                'label' => 'description',
                'required' => false,
            ])
            ->add('invoiceText', InvoiceLabelType::class)

            ->add('labelEnabled', CheckboxType::class, [
                'label'    => 'Enable custom label',
                'required' => false,
            ])

            ->add('labelSymbol', TextType::class, [
                'label'    => 'Label symbol',
                'required' => false,
                'attr'     => ['maxlength' => 10],
            ])
        ;

        // Add event listener to conditionally require symbol
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
            $data = $event->getData();
            $form = $event->getForm();
            if (!empty($data['labelEnabled'])) {
                // make labelSymbol required
                $form->add('labelSymbol', TextType::class, [
                    'label'    => 'Label symbol',
                    'required' => true,
                    'attr'     => ['maxlength' => 10],
                ]);
            }
        });

        if ($isNew || !$isGlobal) {
            $builder
                ->add('project', ProjectType::class, [
                    'required' => false,
                    'help' => 'help.globalActivity',
                    'query_builder' => function (ProjectRepository $repo) use ($builder, $project, $customer) {
                        $query = new ProjectFormTypeQuery($project, $customer);
                        $query->setUser($builder->getOption('user'));
                        $query->setIgnoreDate(true);
                        $query->setWithCustomer(true);

                        return $repo->getQueryBuilderForFormType($query);
                    },
                ]);
        }

        if ($isNew) {
            $builder
                ->add('teams', TeamType::class, [
                    'required' => false,
                    'multiple' => true,
                    'expanded' => false,
                    'by_reference' => false,
                    'help' => 'help.teams',
                ]);
        }

        $this->addCommonFields($builder, $options);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Activity::class,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'admin_activity_edit',
            'currency' => Customer::DEFAULT_CURRENCY,
            'include_budget' => false,
            'include_time' => false,
            'attr' => [
                'data-form-event' => 'kimai.activityUpdate'
            ],
        ]);
    }
}
