<?php
namespace App\Repository;
use App\Entity\Group;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
class GroupRepository extends ServiceEntityRepository {
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, Group::class); }

    public function findCommunityGroups(): array
    {
        return $this->createQueryBuilder('g')
            ->leftJoin('g.groupAdmin', 'groupAdmin')->addSelect('groupAdmin')
            ->leftJoin('g.members', 'members')->addSelect('members')
            ->leftJoin('members.user', 'memberUser')->addSelect('memberUser')
            ->leftJoin('g.threads', 'threads')->addSelect('threads')
            ->orderBy('g.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function isMember(int $groupId, int $userId): bool
    {
        if ($groupId <= 0 || $userId <= 0) {
            return false;
        }

        return $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT 1 FROM group_members WHERE group_id = :group_id AND user_id = :user_id LIMIT 1',
            ['group_id' => $groupId, 'user_id' => $userId],
        ) !== false;
    }

    public function hasPendingRequest(int $groupId, int $userId): bool
    {
        if ($groupId <= 0 || $userId <= 0) {
            return false;
        }

        return $this->getEntityManager()->getConnection()->fetchOne(
            "SELECT 1 FROM group_join_request WHERE group_id = :group_id AND user_id = :user_id AND status = 'PENDING' LIMIT 1",
            ['group_id' => $groupId, 'user_id' => $userId],
        ) !== false;
    }

    /** @return array<int, true> */
    public function findPendingRequestGroupIdsForUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $groupIds = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            "SELECT group_id FROM group_join_request WHERE user_id = :user_id AND status = 'PENDING'",
            ['user_id' => $userId],
        );

        $map = [];
        foreach ($groupIds as $groupId) {
            $map[(int) $groupId] = true;
        }

        return $map;
    }

    /** @return array<int, array{id:int,user_id:int,firstname:string,lastname:string,email:string}> */
    public function findPendingRequestsForGroup(int $groupId): array
    {
        if ($groupId <= 0) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT gjr.id, gjr.user_id, u.firstname, u.lastname, u.email
             FROM group_join_request gjr
             INNER JOIN app_user u ON u.id = gjr.user_id
             WHERE gjr.group_id = :group_id AND gjr.status = 'PENDING'
             ORDER BY gjr.created_at ASC, gjr.id ASC",
            ['group_id' => $groupId],
        );

        return array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'user_id' => (int) $row['user_id'],
                'firstname' => (string) $row['firstname'],
                'lastname' => (string) $row['lastname'],
                'email' => (string) $row['email'],
            ];
        }, $rows);
    }

    public function requestJoin(int $groupId, int $userId): void
    {
        $connection = $this->getEntityManager()->getConnection();

        if ($this->isMember($groupId, $userId)) {
            return;
        }

        if ($this->hasPendingRequest($groupId, $userId)) {
            return;
        }

        $connection->insert('group_join_request', [
            'group_id' => $groupId,
            'user_id' => $userId,
            'status' => 'PENDING',
        ]);
    }

    public function cancelPendingRequest(int $groupId, int $userId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            "DELETE FROM group_join_request WHERE group_id = :group_id AND user_id = :user_id AND status = 'PENDING'",
            ['group_id' => $groupId, 'user_id' => $userId],
        );
    }

    public function approveRequest(int $groupId, int $requestId): ?array
    {
        $connection = $this->getEntityManager()->getConnection();

        return $connection->transactional(function () use ($connection, $groupId, $requestId): ?array {
            $request = $connection->fetchAssociative(
                "SELECT id, group_id, user_id FROM group_join_request WHERE id = :id AND group_id = :group_id AND status = 'PENDING'",
                ['id' => $requestId, 'group_id' => $groupId],
            );

            if ($request === false) {
                return null;
            }

            $connection->executeStatement(
                "UPDATE group_join_request SET status = 'APPROVED' WHERE id = :id",
                ['id' => $requestId],
            );

            $requestGroupId = (int) $request['group_id'];
            $requestUserId = (int) $request['user_id'];

            if (!$this->isMember($requestGroupId, $requestUserId)) {
                $connection->insert('group_members', [
                    'group_id' => $requestGroupId,
                    'user_id' => $requestUserId,
                    'joined_at' => (new \DateTime())->format('Y-m-d H:i:s'),
                ]);
            }

            return [
                'id' => (int) $request['id'],
                'group_id' => $requestGroupId,
                'user_id' => $requestUserId,
            ];
        });
    }

    public function rejectRequest(int $groupId, int $requestId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            "UPDATE group_join_request SET status = 'REJECTED' WHERE id = :id AND group_id = :group_id AND status = 'PENDING'",
            ['id' => $requestId, 'group_id' => $groupId],
        );
    }
}
