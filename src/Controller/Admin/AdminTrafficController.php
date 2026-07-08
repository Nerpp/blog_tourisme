<?php

namespace App\Controller\Admin;

use App\Repository\TrafficEventRepository;
use App\Security\Voter\AdminAccessVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(AdminAccessVoter::ACCESS)]
final class AdminTrafficController extends AbstractController
{
    #[Route('/admin/traffic', name: 'admin_traffic_index', methods: ['GET'])]
    public function index(Request $request, TrafficEventRepository $trafficEventRepository): Response
    {
        [$from, $to, $period] = $this->dateRange($request);
        $includeBots = $request->query->getBoolean('include_bots');
        $pageViews = $trafficEventRepository->countPageViews($from, $to, $includeBots);
        $visitors = $trafficEventRepository->countApproxVisitors($from, $to, $includeBots);
        $statusCodes = $trafficEventRepository->statusCodes($from, $to, $includeBots);
        $error404Count = $this->statusCount($statusCodes, 404);
        $topPages = $trafficEventRepository->topPages($from, $to, 12, $includeBots);
        $topContent = $topPages[0]['contentTitle'] ?? $topPages[0]['path'] ?? 'Aucun trafic';

        return $this->render('admin/traffic/index.html.twig', [
            'from' => $from,
            'to' => $to,
            'period' => $period,
            'include_bots' => $includeBots,
            'kpis' => [
                'page_views' => $pageViews,
                'visitors' => $visitors,
                'today' => $trafficEventRepository->countToday($includeBots),
                'error_404_rate' => $pageViews > 0 ? round(($error404Count / $pageViews) * 100, 1) : 0,
                'top_content' => $topContent,
            ],
            'top_pages' => $topPages,
            'top_articles' => $trafficEventRepository->topContent('article', $from, $to, 8, $includeBots),
            'top_hikes' => $trafficEventRepository->topContent('hike', $from, $to, 8, $includeBots),
            'top_city_visits' => $trafficEventRepository->topContent('city_visit', $from, $to, 8, $includeBots),
            'top_destinations' => $trafficEventRepository->topContent('destination', $from, $to, 8, $includeBots),
            'referrers' => $trafficEventRepository->referrers($from, $to, 10, $includeBots),
            'devices' => $trafficEventRepository->devices($from, $to, $includeBots),
            'browsers' => $trafficEventRepository->browsers($from, $to, $includeBots),
            'status_codes' => $statusCodes,
            'errors_404' => $trafficEventRepository->errors404($from, $to, 10, $includeBots),
            'traffic_by_day' => $trafficEventRepository->trafficByDay($from, $to, $includeBots),
            'traffic_by_hour' => $period === 'today' ? $trafficEventRepository->trafficByHour($from, $to, $includeBots) : [],
            'latest_events' => $trafficEventRepository->latestEvents(25, $includeBots),
            'period_options' => [
                'today' => 'Aujourd’hui',
                '7' => '7 jours',
                '30' => '30 jours',
                '90' => '90 jours',
            ],
        ]);
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: string}
     */
    private function dateRange(Request $request): array
    {
        $period = $request->query->getString('period', '30');
        $to = new \DateTimeImmutable('tomorrow');

        if ($period === 'today') {
            return [new \DateTimeImmutable('today'), $to, 'today'];
        }

        if ($period === 'custom') {
            $fromInput = $request->query->getString('from');
            $toInput = $request->query->getString('to');
            $from = $fromInput !== '' ? new \DateTimeImmutable($fromInput.' 00:00:00') : new \DateTimeImmutable('-29 days midnight');
            $customTo = $toInput !== '' ? (new \DateTimeImmutable($toInput.' 00:00:00'))->modify('+1 day') : $to;

            return [$from, $customTo, 'custom'];
        }

        $days = in_array($period, ['7', '30', '90'], true) ? (int) $period : 30;

        return [(new \DateTimeImmutable('today'))->modify(sprintf('-%d days', $days - 1)), $to, (string) $days];
    }

    /**
     * @param list<array{statusCode: int, views: int}> $statusCodes
     */
    private function statusCount(array $statusCodes, int $status): int
    {
        foreach ($statusCodes as $row) {
            if ($row['statusCode'] === $status) {
                return $row['views'];
            }
        }

        return 0;
    }
}
