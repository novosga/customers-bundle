<?php

declare(strict_types=1);

/*
 * This file is part of the Novo SGA project.
 *
 * (c) Rogerio Lino <rogeriolino@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Novosga\CustomersBundle\Controller;

use Exception;
use Novosga\CustomersBundle\NovosgaCustomersBundle;
use Novosga\Entity\ClienteInterface;
use Novosga\Entity\UsuarioInterface;
use Novosga\Form\ClienteType;
use Novosga\Repository\ClienteRepositoryInterface;
use Novosga\Repository\ViewAtendimentoRepositoryInterface;
use Novosga\Service\ClienteServiceInterface;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Pagerfanta\View\TwitterBootstrap5View;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Customers controller.
 *
 * @author Rogerio Lino <rogeriolino@gmail.com>
 */
#[Route("/", name: "novosga_customers_")]
class DefaultController extends AbstractController
{
    public function __construct(
        private readonly ViewAtendimentoRepositoryInterface $viewAtendimentoRepository,
    ) {
    }

    #[Route("/", name: "index", methods: ['GET'])]
    public function index(
        Request $request,
        ClienteRepositoryInterface $repository,
    ): Response {
        $search = $request->get('q', '');
        /** @var UsuarioInterface */
        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();

        $qb = $repository
            ->createQueryBuilder('e')
            ->select('e')
            ->orderBy('e.nome', 'ASC');

        if (!empty($search)) {
            $where = [
                'e.email LIKE :s',
                'e.documento LIKE :s'
            ];
            $qb->setParameter('s', "%{$search}%");

            $tokens = explode(' ', $search);

            for ($i = 0; $i < count($tokens); $i++) {
                $value = $tokens[$i];
                $v1 = "n{$i}";
                $where[] = "(UPPER(e.nome) LIKE UPPER(:{$v1}))";
                $qb->setParameter($v1, "{$value}%");
            }

            $qb->andWhere(join(' OR ', $where));
        }

        $query = $qb->getQuery();

        $currentPage = max(1, (int) $request->get('p'));

        $adapter = new QueryAdapter($query);
        $view = new TwitterBootstrap5View();
        $pagerfanta = new Pagerfanta($adapter);

        $pagerfanta->setCurrentPage($currentPage);

        $path = $this->generateUrl('novosga_customers_index');
        $html = $view->render(
            $pagerfanta,
            function ($page) use ($request, $path) {
                $params = [];
                $vars = ['q'];
                foreach ($vars as $name) {
                    $value = $request->get($name);
                    if ($value !== null) {
                        $params[] = "{$name}={$value}";
                    }
                }
                $path .= "?p={$page}";
                if (!empty($params)) {
                    $path .= '&' . implode('&', $params);
                }
                return $path;
            },
            [
                'proximity' => 3,
                'prev_message' => '←',
                'next_message' => '→',
            ]
        );

        $clientes = $pagerfanta->getCurrentPageResults();

        return $this->render('@NovosgaCustomers/default/index.html.twig', [
            'clientes' => $clientes,
            'paginacao' => $html,
        ]);
    }

    #[Route("/new", name: "new", methods: ['GET', 'POST'])]
    public function add(
        Request $request,
        ClienteServiceInterface $service,
        TranslatorInterface $translator,
    ): Response {
        $entity = $service->build();

        return $this->form($request, $service, $translator, $entity);
    }

    #[Route("/{id}/edit", name: "edit", methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        ClienteServiceInterface $service,
        TranslatorInterface $translator,
        ?int $id = null,
    ): Response {
        /** @var UsuarioInterface */
        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();

        $entity = $service->getById($id);
        if (!$entity) {
            throw $this->createNotFoundException();
        }

        return $this->form($request, $service, $translator, $entity);
    }

    private function form(
        Request $request,
        ClienteServiceInterface $service,
        TranslatorInterface $translator,
        ClienteInterface $entity,
    ): Response {
        $form = $this
            ->createForm(ClienteType::class, $entity)
            ->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $service->save($entity);

                $this->addFlash(
                    'success',
                    $translator->trans(
                        'label.add_success',
                        [],
                        NovosgaCustomersBundle::getDomain(),
                    )
                );

                return $this->redirectToRoute('novosga_customers_edit', [
                    'id' => $entity->getId(),
                ]);
            } catch (Exception $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        $atendimentos = [];

        if ($entity->getId()) {
            $atendimentos = $this->viewAtendimentoRepository->findBy(
                [ 'cliente' => $entity ],
                [ 'id' => 'DESC' ],
                10,
            );
        }

        return $this->render('@NovosgaCustomers/default/form.html.twig', [
            'entity' => $entity,
            'atendimentos' => $atendimentos,
            'form' => $form,
        ]);
    }
}
