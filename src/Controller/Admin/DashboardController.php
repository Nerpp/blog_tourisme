<?php

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
final class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    public function index(): Response
    {
        return $this->redirect($this->adminUrlGenerator
            ->setController(ArticleCrudController::class)
            ->generateUrl());
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Blog Tourisme Admin');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Tableau de bord', 'fa fa-house');

        yield MenuItem::section('Contenu');
        yield MenuItem::linkTo(ArticleCrudController::class, 'Articles', 'fa fa-newspaper');
        yield MenuItem::linkTo(PlaceCrudController::class, 'Lieux à visiter', 'fa fa-location-dot');
        yield MenuItem::linkTo(DestinationCrudController::class, 'Destinations', 'fa fa-map-location-dot');
        yield MenuItem::linkTo(CategoryCrudController::class, 'Catégories', 'fa fa-folder');
        yield MenuItem::linkTo(TagCrudController::class, 'Tags', 'fa fa-tags');
        yield MenuItem::linkTo(MediaAssetCrudController::class, 'Médias', 'fa fa-photo-film');

        yield MenuItem::section('Commentaires');
        yield MenuItem::linkTo(CommentCrudController::class, 'Commentaires', 'fa fa-comments');
        yield MenuItem::linkTo(CommentReportCrudController::class, 'Signalements', 'fa fa-flag');
        yield MenuItem::linkTo(ModerationKeywordCrudController::class, 'Mots-clés de modération', 'fa fa-shield-halved');

        yield MenuItem::section('Utilisateurs');
        yield MenuItem::linkTo(UserCrudController::class, 'Utilisateurs', 'fa fa-users');

        yield MenuItem::section('Outils terrain');
        yield MenuItem::linkTo(QuickHikeController::class, 'Nouvelle rando rapide', 'fa fa-person-hiking');

        yield MenuItem::section();
        yield MenuItem::linkToRoute('Retour site public', 'fa fa-arrow-left', 'app_home');
    }
}
