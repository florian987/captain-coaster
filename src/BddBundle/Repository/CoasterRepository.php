<?php

namespace BddBundle\Repository;

/**
 * CoasterRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class CoasterRepository extends \Doctrine\ORM\EntityRepository
{
    public function findAllNameAndSlug()
    {
        return $this->getEntityManager()
            ->createQueryBuilder()
            ->select('CONCAT(c.name, \' (\', p.name, \')\') AS name')
            ->addSelect('c.slug')
            ->from('BddBundle:Coaster', 'c')
            ->innerJoin('c.park', 'p', 'WITH', 'c.park = p.id')
            ->getQuery()
            ->getResult();
    }
}
