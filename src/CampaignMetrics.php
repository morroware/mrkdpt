<?php

declare(strict_types=1);

final class CampaignMetricsRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function forCampaign(int $campaignId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM campaign_metrics WHERE campaign_id = :cid ORDER BY metric_date DESC');
        $stmt->execute([':cid' => $campaignId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function add(int $campaignId, array $data): array
    {
        $this->pdo->prepare('INSERT INTO campaign_metrics(campaign_id, metric_date, spend, revenue, impressions, clicks, conversions, notes, created_at) VALUES(:cid,:md,:s,:r,:i,:cl,:co,:n,:ca)')->execute([
            ':cid' => $campaignId,
            ':md' => $data['metric_date'] ?? gmdate('Y-m-d'),
            ':s' => (float)($data['spend'] ?? 0),
            ':r' => (float)($data['revenue'] ?? 0),
            ':i' => (int)($data['impressions'] ?? 0),
            ':cl' => (int)($data['clicks'] ?? 0),
            ':co' => (int)($data['conversions'] ?? 0),
            ':n' => $data['notes'] ?? '',
            ':ca' => gmdate(DATE_ATOM),
        ]);

        // Update campaign totals
        $this->refreshCampaignTotals($campaignId);

        $id = (int)$this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('SELECT * FROM campaign_metrics WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function delete(int $id): bool
    {
        // Get campaign_id before deleting
        $stmt = $this->pdo->prepare('SELECT campaign_id FROM campaign_metrics WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $del = $this->pdo->prepare('DELETE FROM campaign_metrics WHERE id = :id');
        $del->execute([':id' => $id]);

        if ($row) {
            $this->refreshCampaignTotals((int)$row['campaign_id']);
        }

        return $del->rowCount() > 0;
    }

    /**
     * ROI and performance summary for a single campaign.
     */
    public function summary(int $campaignId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                COALESCE(SUM(spend), 0) as total_spend,
                COALESCE(SUM(revenue), 0) as total_revenue,
                COALESCE(SUM(impressions), 0) as total_impressions,
                COALESCE(SUM(clicks), 0) as total_clicks,
                COALESCE(SUM(conversions), 0) as total_conversions,
                COUNT(*) as data_points
            FROM campaign_metrics WHERE campaign_id = :cid
        ");
        $stmt->execute([':cid' => $campaignId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $spend = (float)($row['total_spend'] ?? 0);
        $revenue = (float)($row['total_revenue'] ?? 0);
        $impressions = (int)($row['total_impressions'] ?? 0);
        $clicks = (int)($row['total_clicks'] ?? 0);
        $conversions = (int)($row['total_conversions'] ?? 0);

        $roi = $spend > 0 ? round(($revenue - $spend) / $spend * 100, 2) : 0;
        $ctr = $impressions > 0 ? round($clicks / $impressions * 100, 2) : 0;
        $cvr = $clicks > 0 ? round($conversions / $clicks * 100, 2) : 0;
        $cpa = $conversions > 0 ? round($spend / $conversions, 2) : 0;
        $roas = $spend > 0 ? round($revenue / $spend, 2) : 0;

        return [
            'total_spend' => $spend,
            'total_revenue' => $revenue,
            'total_impressions' => $impressions,
            'total_clicks' => $clicks,
            'total_conversions' => $conversions,
            'roi_percent' => $roi,
            'ctr_percent' => $ctr,
            'conversion_rate_percent' => $cvr,
            'cost_per_acquisition' => $cpa,
            'roas' => $roas,
            'data_points' => (int)($row['data_points'] ?? 0),
        ];
    }

    /**
     * Compare multiple campaigns side by side.
     */
    public function compare(array $campaignIds): array
    {
        $results = [];
        foreach ($campaignIds as $id) {
            $campaign = $this->pdo->prepare('SELECT * FROM campaigns WHERE id = :id LIMIT 1');
            $campaign->execute([':id' => (int)$id]);
            $c = $campaign->fetch(PDO::FETCH_ASSOC);
            if ($c) {
                $results[] = [
                    'campaign' => $c,
                    'metrics' => $this->summary((int)$id),
                    'daily' => $this->forCampaign((int)$id),
                ];
            }
        }
        return $results;
    }

    private function refreshCampaignTotals(int $campaignId): void
    {
        $stmt = $this->pdo->prepare("SELECT COALESCE(SUM(spend),0) as spend, COALESCE(SUM(revenue),0) as revenue FROM campaign_metrics WHERE campaign_id = :cid");
        $stmt->execute([':cid' => $campaignId]);
        $totals = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->pdo->prepare('UPDATE campaigns SET spend_to_date = :s, revenue = :r WHERE id = :id')->execute([
            ':s' => (float)$totals['spend'],
            ':r' => (float)$totals['revenue'],
            ':id' => $campaignId,
        ]);
    }
}
