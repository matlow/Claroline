<?php

namespace Claroline\CoreBundle\Controller;

use Doctrine\ORM\EntityRepository;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class WorkspaceUserController extends Controller
{
    /*******************/
    /* USER MANAGEMENT */
    /*******************/

    const ABSTRACT_WS_CLASS = 'Claroline\CoreBundle\Entity\Workspace\AbstractWorkspace';
    const NUMBER_USER_PER_ITERATION = 25;

    /**
     * Renders the users management page with its layout
     *
     * @param integer $workspaceId
     *
     * @return Response
     */
    public function usersManagementAction($workspaceId)
    {
        $em = $this->get('doctrine.orm.entity_manager');
        $workspace = $em->getRepository(self::ABSTRACT_WS_CLASS)->find($workspaceId);
        $users = $em->getRepository('ClarolineCoreBundle:User')->getUsersOfWorkspace($workspace);
        $this->checkRegistration($workspace);

        return $this->render('ClarolineCoreBundle:Workspace:tools\user_management.html.twig', array(
            'workspace' => $workspace, 'users' => $users)
        );
    }

    /**
     * Renders the unregistered user list layout for a workspace.
     *
     * @param integer $workspaceId
     *
     * @return Response
     */
    public function unregiseredUsersListAction($workspaceId)
    {
        $em = $this->get('doctrine.orm.entity_manager');
        $workspace = $em->getRepository(self::ABSTRACT_WS_CLASS)->find($workspaceId);
        $this->checkRegistration($workspace);

        return $this->render('ClarolineCoreBundle:Workspace:tools\unregistered_user_list_layout.html.twig', array(
                'workspace' => $workspace)
        );
    }

    /**
     * Renders the user parameter page with its layout and
     * edit the user parameters for the selected workspace.
     *
     * @param integer $workspaceId
     *
     * @return Response
     */
    public function userParametersAction($workspaceId, $userId)
    {
        $em = $this->get('doctrine.orm.entity_manager');
        $workspace = $em->getRepository(self::ABSTRACT_WS_CLASS)->find($workspaceId);
        $this->checkIfAdmin($workspace);
        //more~ only the workspace manager can do it maybe ?
        $user = $em->getRepository('ClarolineCoreBundle:User')->find($userId);
        $role = $em->getRepository('ClarolineCoreBundle:User')->getRoleOfWorkspace($userId, $workspaceId);
        $defaultData = array('role' => $role[0]);
        $form = $this->createFormBuilder($defaultData)
            ->add(
                'role', 'entity', array(
                'class' => 'Claroline\CoreBundle\Entity\WorkspaceRole',
                'property' => 'translationKey',
                'query_builder' => function(EntityRepository $er) use ($workspaceId) {
                    return $er->createQueryBuilder('wr')
                        ->add('where', "wr.workspace = {$workspaceId}");
                }
            ))
            ->getForm();

        if ($this->getRequest()->getMethod() == 'POST') {
            $form->bind($this->getRequest());
            $data = $form->getData();
            $newRole = $data['role'];

            //verifications: his role cannot be changed
            if ($newRole->getId() != $workspace->getManagerRole()->getId()){
                $userIds = array($userId);
                $this->checkRemoveManagerRoleIsValid($userIds, $workspace);
            }

            $user->removeRole($role[0]);
            $user->addRole($newRole);
            $em->persist($user);
            $em->flush();
            $route = $this->get('router')->generate('claro_workspace_tools_users_management', array('workspaceId' => $workspaceId));

            return new RedirectResponse($route);
        }

        return $this->render('ClarolineCoreBundle:Workspace:tools\user_parameters.html.twig', array(
                'workspace' => $workspace, 'user' => $user, 'form' => $form->createView())
        );
    }

    /**
     * Renders a list of registered users for a workspace.
     * It'll search every users whose username, firstname or lastname match $search.
     *
     * @param string $search
     * @param integer $workspaceId
     * @param string $format
     *
     * @return Response
     */
    public function searchRegisteredUsersAction($search, $workspaceId, $offset)
    {
        $em = $this->get('doctrine.orm.entity_manager');
        $workspace = $em->getRepository(self::ABSTRACT_WS_CLASS)->find($workspaceId);
        $this->checkRegistration($workspace);
        $users = $em->getRepository('ClarolineCoreBundle:User')->searchPaginatedUsersOfWorkspace($workspaceId, $search, $offset, self::NUMBER_USER_PER_ITERATION);
        $content = $this->renderView("ClarolineCoreBundle:Administration:user_list.json.twig", array('users' => $users));
        $response = new Response($content);
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }


    /**
     * Renders a list of unregistered users for a workspace.
     * It'll search every users whose username or lastname or firstname match $search.
     *
     * @param string $search
     * @param integer $workspaceId
     * @param string $format
     *
     * @return Response
     */
    public function searchUnregisteredUsersAction($search, $workspaceId, $offset)
    {
        $em = $this->getDoctrine()->getEntityManager();
        $workspace = $em->getRepository(self::ABSTRACT_WS_CLASS)->find($workspaceId);
        $this->checkRegistration($workspace);
        $users = $em->getRepository('ClarolineCoreBundle:User')->getUnregisteredUsersOfWorkspaceFromGenericSearch($search, $workspace, $offset, self::NUMBER_USER_PER_ITERATION);
        $content = $this->renderView("ClarolineCoreBundle:Administration:user_list.json.twig", array('users' => $users));
        $response = new Response($content);
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

    /**
     * Adds a user to a workspace
     * if $userId = 'null', the user will be the current logged user
     * if requested through ajax, it'll respond with a json object containing the user datas
     * otherwise it'll redirect to the workspace list.
     *
     * @param integer $workspaceId
     *
     * @return RedirectResponse
     */
    public function addUserAction($workspaceId, $userId)
    {
        $request = $this->get('request');
        $em = $this->get('doctrine.orm.entity_manager');
        $user = $em->find('Claroline\CoreBundle\Entity\User', $userId);
        $workspace = $em->getRepository(self::ABSTRACT_WS_CLASS)->find($workspaceId);
        $user->addRole($workspace->getCollaboratorRole());
        $em->flush();

        if ($request->isXmlHttpRequest()) {
            return $this->render('ClarolineCoreBundle:Administration:user_list.json.twig', array('users' => array($user)));
        }

        $route = $this->get('router')->generate('claro_workspace_list');

        return new RedirectResponse($route);
    }

    /**
     * Adds many users to a workspace.
     * It should be used with ajax and a list of userIds as parameter.
     *
     * @param integer $workspaceId
     *
     * @return Response
     */
    //todo: detach($user)
    //todo: flush outsite the loop
    public function multiAddUserAction($workspaceId)
    {
        $params = $this->get('request')->query->all();
        unset($params['_']);

        $em = $this->get('doctrine.orm.entity_manager');
        $workspace = $em->getRepository(self::ABSTRACT_WS_CLASS)->find($workspaceId);
        $users = array();

        foreach ($params as $userId) {
             $user = $em->find('Claroline\CoreBundle\Entity\User', $userId);
             $users[] = $user;
             $user->addRole($workspace->getCollaboratorRole());
             $em->flush();
        }

        //small hack to get the current workspace as the only workspace role. Do not flush after this !
        foreach ($users as $user){
            $user->setWorkspaceRoleCollection($workspace->getCollaboratorRole());
        }

        $content = $this->renderView('ClarolineCoreBundle:Administration:user_list.json.twig', array('users' => $users));
        $response = new Response($content);
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

    /**
     * Renders a list of registered users for a workspace
     *
     * @param integer $workspaceId
     * @param integer $page
     *
     * @return Response
     */
    public function paginatedUsersOfWorkspaceAction($workspaceId, $offset)
    {
        $em = $this->get('doctrine.orm.entity_manager');
        $workspace = $em->getRepository(self::ABSTRACT_WS_CLASS)->find($workspaceId);
        $this->checkRegistration($workspace);
        $users = $em->getRepository('ClarolineCoreBundle:User')->findPaginatedUsersOfWorkspace($workspaceId, $offset, self::NUMBER_USER_PER_ITERATION);
        $content = $this->renderView("ClarolineCoreBundle:Administration:user_list.json.twig", array('users' => $users));
        $response = new Response($content);
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

    /**
     * Renders a list of unregistered users for a workspace.
     * if page = 1, it'll render users 1-25
     * if page = 2, it'll render users 26-50
     * if page = 3, it'll render users 51-75
     * ...
     *
     * @param integer $workspaceId
     * @param integer $offset
     *
     * @return Response
     */
    public function paginatedUnregisteredUsersAction($workspaceId, $offset)
    {
        $em = $this->get('doctrine.orm.entity_manager');
        $workspace = $em->getRepository(self::ABSTRACT_WS_CLASS)->find($workspaceId);
        $this->checkRegistration($workspace);
        $users = $em->getRepository('ClarolineCoreBundle:User')->getLazyUnregisteredUsersOfWorkspace($workspace, $offset, self::NUMBER_USER_PER_ITERATION);
        $content = $this->renderView("ClarolineCoreBundle:Administration:user_list.json.twig", array('users' => $users));
        $response = new Response($content);
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

    /**
     * Removes a user from a workspace.
     * If it was requested through ajax, it will respond "success".
     * otherwise it'll redirect to the workspace list for a user.
     *
     * @param integer $userId
     * @param integer $workspaceId
     *
     * @return Response
     */

    public function removeUserAction($userId, $workspaceId)
    {
        $em = $this->get('doctrine.orm.entity_manager');
        $user = $em->find('Claroline\CoreBundle\Entity\User', $userId);
        $workspace = $em->getRepository(self::ABSTRACT_WS_CLASS)->find($workspaceId);
        $this->checkIfAdmin($workspace);
        $userIds = array($user->getId());
        $this->checkRemoveManagerRoleIsValid($userIds, $workspace);
        $roles = $workspace->getWorkspaceRoles();

        foreach ($roles as $role) {
            $user->removeRole($role);
        }

        $em->flush();

        return new Response("success", 204);
    }

    /**
     * Removes many users from a workspace. ( ?0=1&1=2... )
     * If it was requested through ajax, it will respond "success".
     * otherwise it'll redirect to the workspace list for a user.
     *
     * @param integer $workspaceId
     *
     * @return Response
     */
    public function removeMultipleUsersAction($workspaceId)
    {
        $em = $this->get('doctrine.orm.entity_manager');
        $workspace = $em->getRepository(self::ABSTRACT_WS_CLASS)->find($workspaceId);
        $this->checkIfAdmin($workspace);
        $roles = $workspace->getWorkspaceRoles();
        $params = $this->get('request')->query->all();
        unset($params['_']);
        $this->checkRemoveManagerRoleIsValid($params, $workspace);

        foreach ($params as $userId) {
            $user = $em->find('Claroline\CoreBundle\Entity\User', $userId);
            if (null != $user) {
                foreach ($roles as $role) {
                    $user->removeRole($role);
                }
            }
        }

        $em->flush();

        return new Response("success", 204);
    }

    /*******************/
    /* PRIVATE METHODS */
    /*******************/

    private function checkRemoveManagerRoleIsValid($parameters, $workspace)
    {
        $em = $this->get('doctrine.orm.entity_manager');
        $countRemovedManagers = 0;

        foreach ($parameters as $userId) {
            $user = $em->find('Claroline\CoreBundle\Entity\User', $userId);

            if (null !== $user) {
                if ($workspace == $user->getPersonalWorkspace()) {
                    throw new LogicException("You can't remove the original manager from a personal workspace");
                }
                if ($user->hasRole($workspace->getManagerRole()->getName())) {
                    $countRemovedManagers++;
                }
            }
        }


        $userManagers = $em->getRepository('Claroline\CoreBundle\Entity\User')->getUsersOfWorkspace($workspace, $workspace->getManagerRole(), true);
        $countUserManagers = count($userManagers);

        if ($countRemovedManagers >= $countUserManagers) {
            throw new LogicException("You can't remove every managers(you're trying to remove {$countRemovedManagers} manager(s) out of {$countUserManagers})");
        }
    }

    private function checkRegistration($workspace)
    {
        $authorization = false;

        foreach ($workspace->getWorkspaceRoles() as $role) {
            if ($this->get('security.context')->isGranted($role->getName())) {
                $authorization = true;
            }
        }

        if ($authorization === false) {
            throw new AccessDeniedHttpException();
        }
    }

    private function checkIfAdmin($workspace)
    {
        if (!$this->get('security.context')->isGranted($workspace->getManagerRole()->getName())) {
            throw new AccessDeniedHttpException();
        }
    }
}

