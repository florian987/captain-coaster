<?php

namespace App\Repository;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\NonUniqueResultException;

/**
 * UserRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class UserRepository extends EntityRepository
{
    /**
     * @return \Doctrine\ORM\Query
     */
    public function getUserList()
    {
        return $this->getEntityManager()
            ->createQueryBuilder()
            ->select('u')
            ->addSelect('count(r.id) as total_ratings')
            ->from('App:User', 'u')
            ->innerJoin('u.ratings', 'r', 'WITH', 'r.user = u')
            ->groupBy('r.user')
            ->orderBy('total_ratings', 'desc')
            ->getQuery();
    }

    /**
     * Returns users that have recently up
     * @param int $sinceHours
     * @return mixed
     */
    public function getUsersWithRecentRatingOrTopUpdate($sinceHours = 1)
    {
        $date = new \DateTime('- '.$sinceHours.' hours');

        return $this->getEntityManager()
            ->createQueryBuilder()
            ->select('u')
            ->from('App:User', 'u')
            ->leftJoin('u.ratings', 'r')
            ->leftJoin('u.tops', 'l')
            ->where('r.updatedAt > :date')
            ->orWhere('l.updatedAt > :date')
            ->setParameter('date', $date)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return mixed
     */
    public function getAllForSearch()
    {
        return $this->getEntityManager()
            ->createQueryBuilder()
            ->select('u.displayName as name')
            ->addSelect('u.slug')
            ->from('App:User', 'u')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count all users
     *
     * @return mixed
     */
    public function countAll()
    {
        try {
            return $this->getEntityManager()
                ->createQueryBuilder()
                ->select('count(1) as nb_users')
                ->from('App:User', 'u')
                ->getQuery()
                ->getSingleScalarResult();
        } catch (NonUniqueResultException $e) {
            return 0;
        }
    }
}
