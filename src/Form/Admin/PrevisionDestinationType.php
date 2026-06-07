<?php

namespace App\Form\Admin;

use App\Entity\PrevisionDestination;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

final class PrevisionDestinationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', ChoiceType::class, [
                'label' => 'Type de sortie prévue',
                'choices' => [
                    'Randonnée' => 'Randonnée',
                    'Visite' => 'Visite',
                ],
                'constraints' => [
                    new NotBlank(message: 'Choisissez un type de sortie prévue.'),
                    new Length(max: 180),
                ],
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'Statut',
                'choices' => [
                    'Idée' => PrevisionDestination::STATUS_IDEA,
                    'À vérifier' => PrevisionDestination::STATUS_TO_CHECK,
                    'À visiter' => PrevisionDestination::STATUS_TO_VISIT,
                    'Repérée' => PrevisionDestination::STATUS_SPOTTED,
                    'Abandonnée' => PrevisionDestination::STATUS_ABANDONED,
                ],
            ])
            ->add('source', ChoiceType::class, [
                'label' => 'Source',
                'required' => false,
                'placeholder' => 'Non renseignée',
                'choices' => [
                    'Manuel' => PrevisionDestination::SOURCE_MANUAL,
                    'Recherche' => PrevisionDestination::SOURCE_SEARCH,
                    'GPS' => PrevisionDestination::SOURCE_GPS,
                    'Point placé sur carte' => PrevisionDestination::SOURCE_MANUAL_MAP,
                ],
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Notes',
                'required' => false,
            ])
            ->add('country', TextType::class, [
                'label' => 'Pays',
                'required' => false,
            ])
            ->add('region', TextType::class, [
                'label' => 'Région',
                'required' => false,
            ])
            ->add('department', TextType::class, [
                'label' => 'Département',
                'required' => false,
            ])
            ->add('commune', TextType::class, [
                'label' => 'Commune',
                'required' => false,
            ])
            ->add('inseeCode', TextType::class, [
                'label' => 'Code INSEE',
                'required' => false,
            ])
            ->add('postalCode', TextType::class, [
                'label' => 'Code postal',
                'required' => false,
            ])
            ->add('latitude', NumberType::class, [
                'label' => 'Latitude',
                'required' => false,
                'html5' => true,
                'scale' => 7,
                'attr' => ['step' => 'any'],
            ])
            ->add('longitude', NumberType::class, [
                'label' => 'Longitude',
                'required' => false,
                'html5' => true,
                'scale' => 7,
                'attr' => ['step' => 'any'],
            ])
            ->add('gpsAccuracy', NumberType::class, [
                'label' => 'Précision GPS',
                'required' => false,
                'html5' => true,
                'scale' => 2,
                'attr' => ['step' => 'any'],
            ])
            ->add('priority', ChoiceType::class, [
                'label' => 'Priorité',
                'required' => false,
                'placeholder' => 'Non renseignée',
                'choices' => [
                    'Basse' => PrevisionDestination::PRIORITY_LOW,
                    'Moyenne' => PrevisionDestination::PRIORITY_MEDIUM,
                    'Haute' => PrevisionDestination::PRIORITY_HIGH,
                ],
            ])
            ->add('plannedPeriod', TextType::class, [
                'label' => 'Période prévue',
                'required' => false,
                'help' => 'Exemples : printemps, été, à faire après pluie, hors saison.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PrevisionDestination::class,
        ]);
    }
}
