<?php

namespace Claroline\CoreBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use Claroline\CoreBundle\Entity\User;
use Claroline\CoreBundle\Entity\Group;
use Claroline\CoreBundle\Entity\Workspace\AbstractWorkspace;
use Claroline\CoreBundle\Form\ProfileType;
use Claroline\CoreBundle\Form\GroupType;
use Claroline\CoreBundle\Form\GroupSettingsType;
use Claroline\CoreBundle\Form\PlatformParametersType;
use Claroline\CoreBundle\Library\Workspace\Configuration;
use Claroline\CoreBundle\Library\Plugin\Event\PluginOptionsEvent;
use Claroline\CoreBundle\Library\Widget\Event\ConfigureWidgetEvent;

/**
 * Controller of the platform administration section (users, groups,
 * workspaces, platform settings, etc.).
 */
class AdministrationController extends Controller
{
    const USER_PER_PAGE = 40;
    const GROUP_PER_PAGE = 40;

    /**
     * Displays the administration section index.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function indexAction()
    {
        return $this->render('ClarolineCoreBundle:Administration:index.html.twig');
    }

    /**
     * Displays the user creation form.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function userCreationFormAction()
    {
        $userRoles = $this->get('security.context')->getToken()->getUser()->getOwnedRoles();
        $form = $this->createForm(new ProfileType($userRoles));

        return $this->render(
            'ClarolineCoreBundle:Administration:user_creation_form.html.twig',
            array('form_complete_user' => $form->createView())
        );
    }

    /**
     * Creates an user (and its personal workspace) and redirects to the user list.
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function createUserAction()
    {
        $request = $this->get('request');
        $userRoles = $this->get('security.context')->getToken()->getUser()->getOwnedRoles();
        $form = $this->get('form.factory')->create(new ProfileType($userRoles), new User());
        $form->bindRequest($request);

        if ($form->isValid()) {
            $user = $form->getData();
            $em = $this->getDoctrine()->getEntityManager();
            $em->persist($user);
            $type = Configuration::TYPE_SIMPLE;
            $config = new Configuration();
            $config->setWorkspaceType($type);
            $config->setWorkspaceName($user->getUsername());
            $config->setWorkspaceCode('PERSO');
            $wsCreator = $this->get('claroline.workspace.creator');
            $workspace = $wsCreator->createWorkspace($config, $user);
            $workspace->setType(AbstractWorkspace::USER_REPOSITORY);
            $user->addRole($workspace->getManagerRole());
            $user->setPersonalWorkspace($workspace);
            $em->persist($workspace);
            $em->flush();

            return $this->redirect($this->generateUrl('claro_admin_user_list'));
        }

        return $this->render(
            'ClarolineCoreBundle:Administration:user_creation_form.html.twig',
            array('form_complete_user' => $form->createView())
        );
    }

    /**
     * Removes many users from the platform.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function multiDeleteUserAction()
    {
        $em = $this->getDoctrine()->getEntityManager();
        $params = $this->get('request')->query->all();

        if(isset($params['id'])){
            foreach ($params['id'] as $userId) {
                $user = $em->getRepository('Claroline\CoreBundle\Entity\User')->find($userId);
                $em->remove($user);
            }
        }

        $em->flush();

        return new Response('user(s) removed', 204);
    }

    /**
     * Displays the platform user list.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function userListAction()
    {
        return $this->render(
            'ClarolineCoreBundle:Administration:user_list_main.html.twig');
    }

    /**
     * Returns the platform users.
     *
     * @param $offset
     * @param $format
     *
     * @return Response
     */
    public function usersAction($offset, $format)
    {
        $em = $this->getDoctrine()->getEntityManager();
        $paginatorUsers = $em->getRepository('Claroline\CoreBundle\Entity\User')->users($offset, self::USER_PER_PAGE, \Claroline\CoreBundle\Repository\UserRepository::PLATEFORM_ROLE);
        $users = $this->paginatorToArray($paginatorUsers);

        $content = $this->renderView(
            "ClarolineCoreBundle:Administration:user_list.{$format}.twig", array('users' => $users));

        $response = new Response($content);

        if  ($format == 'json') {
            $response->headers->set('Content-Type', 'application/json');
        }

        return $response;
    }

    /**
     * Returns the platform users whose name, username or lastname matche $search.
     *
     * @param integer $offset
     * @param string $format
     * @param string $search
     *
     * @return Response
     */
    public function searchUsersAction($offset, $search, $format)
    {
        $em = $this->getDoctrine()->getEntityManager();
        $paginatorUsers = $em->getRepository('Claroline\CoreBundle\Entity\User')->searchUsers($search, $offset, self::USER_PER_PAGE, \Claroline\CoreBundle\Repository\UserRepository::PLATEFORM_ROLE);
        $users = $this->paginatorToArray($paginatorUsers);
        $content = $this->renderView(
            "ClarolineCoreBundle:Administration:user_list.{$format}.twig", array('users' => $users));

        $response = new Response($content);

        if  ($format == 'json') {
            $response->headers->set('Content-Type', 'application/json');
        }

        return $response;
    }

    /**
     * Returns the group users.
     *
     * @param integer $groupId
     * @param string $offset
     *
     * @return Response
     */
    // Doesn't work yet due to a sql error from the repository
    public function usersOfGroupAction($groupId, $offset)
    {
        $em = $this->getDoctrine()->getEntityManager();
        $paginatorUsers = $em->getRepository('Claroline\CoreBundle\Entity\User')->usersOfGroup($groupId, $offset, self::USER_PER_PAGE);
        $users = $this->paginatorToArray($paginatorUsers);

        $content = $this->renderView(
            "ClarolineCoreBundle:Administration:user_list.json.twig", array('users' => $users));

        $response = new Response($content);
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

    /**
     * Returns the group users whose name or username or lastname matche $search.
     *
     * @param integer $groupId
     * @param integer $offset
     * @param string $search
     *
     * @return Response
     */
    // Doesn't work yet due to a sql error from the repository
    public function searchUsersOfGroupAction($groupId, $offset, $search)
    {
        $em = $this->getDoctrine()->getEntityManager();
        $paginatorUsers = $em->getRepository('Claroline\CoreBundle\Entity\User')->searchUsersOfGroup($search, $groupId, $offset, self::USER_PER_PAGE);
        $users = $this->paginatorToArray($paginatorUsers);

        $content = $this->renderView(
            "ClarolineCoreBundle:Administration:user_list.json.twig", array('users' => $users));

        $response = new Response($content);
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

    /**
     * Returns the platform group list.
     *
     * @param $offset the offset.
     * @param $format the format.
     *
     * @return Response.
     */
    public function groupsAction($offset, $format)
    {
        $em = $this->getDoctrine()->getEntityManager();
        $paginatorGroups = $em->getRepository('Claroline\CoreBundle\Entity\Group')->groups($offset, self::GROUP_PER_PAGE);
        $groups = $this->paginatorToArray($paginatorGroups);
        $content = $this->renderView(
            "ClarolineCoreBundle:Administration:group_list.{$format}.twig", array('groups' => $groups));
        $response = new Response($content);

        if  ($format == 'json') {
            $response->headers->set('Content-Type', 'application/json');
        }

        return $response;
    }

    /*
     * Returns the platform group list whose names match $search.
     *
     * @param $offset the $offset.
     * @param $search the searched name.
     *
     * @return Response.
     */
    public function searchGroupsAction($offset, $search, $format)
    {
        $em = $this->getDoctrine()->getEntityManager();
        $paginatorGroups = $em->getRepository('Claroline\CoreBundle\Entity\Group')->searchGroups($search, $offset, self::GROUP_PER_PAGE);
        $groups = $this->paginatorToArray($paginatorGroups);
        $content = $this->renderView(
            "ClarolineCoreBundle:Administration:group_list.{$format}.twig", array('groups' => $groups));
        $response = new Response($content);

        if ($format == 'json') {
            $response->headers->set('Content-Type', 'application/json');
        }

        return $response;
    }

    /**
     * Displays the group creation form.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function groupCreationFormAction()
    {
        $form = $this->createForm(new GroupType(), new Group());

        return $this->render(
            'ClarolineCoreBundle:Administration:group_creation_form.html.twig',
            array('form_group' => $form->createView())
        );
    }

    /**
     * Creates a group and redirects to the group list.
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function createGroupAction()
    {
        $request = $this->get('request');
        $form = $this->get('form.factory')->create(new GroupType(), new Group());
        $form->bindRequest($request);

        if ($form->isValid()) {
            $group = $form->getData();
            $em = $this->getDoctrine()->getEntityManager();
            $em->persist($group);
            $em->flush();

            return $this->redirect($this->generateUrl('claro_admin_group_list'));
        }

        return $this->render(
            'ClarolineCoreBundle:Administration:group_creation_form.html.twig',
            array('form_group' => $form->createView())
        );
    }

    /**
     * Displays the platform group list.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function groupListAction()
    {
        $em = $this->getDoctrine()->getEntityManager();
        $query = $em->createQuery('SELECT COUNT(g.id) FROM Claroline\CoreBundle\Entity\Group g');
        $count = $query->getSingleScalarResult();
        $pages = ceil($count/self::USER_PER_PAGE);

        return $this->render(
            'ClarolineCoreBundle:Administration:group_list_main.html.twig',
            array('pages' => $pages)
        );
    }

    /**
     * Displays the users of a group.
     *
     * @param integer $groupId
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function groupUserListAction($groupId)
    {
        $em = $this->getDoctrine()->getEntityManager();
        $group = $em->getRepository('Claroline\CoreBundle\Entity\Group')->find($groupId);

        return $this->render(
            'ClarolineCoreBundle:Administration:group_user_list_main.html.twig',
            array('group' => $group)
        );
    }

    /**
     * Displays the user list with a control allowing to add them to a group.
     *
     * @param integer $groupId
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function addUserToGroupLayoutAction($groupId)
    {
        $em = $this->getDoctrine()->getEntityManager();
        $group = $em->getRepository('Claroline\CoreBundle\Entity\Group')->find($groupId);

        return $this->render(
            'ClarolineCoreBundle:Administration:add_user_to_group_main.html.twig',
            array('group' => $group)
        );
    }

    /**
     * Returns a list of users not registered to the Group $group.
     *
     * @param integer $group
     * @param integer $offset
     *
     * @return Response
     */
    public function grouplessUsersAction($groupId, $offset)
    {
        $em = $this->getDoctrine()->getEntityManager();
        $paginatorUsers = $em->getRepository('Claroline\CoreBundle\Entity\User')->unregisteredUsersOfGroup($groupId, $offset, self::USER_PER_PAGE);
        $users = $this->paginatorToArray($paginatorUsers);

        $content = $this->renderView(
            "ClarolineCoreBundle:Administration:user_list.json.twig", array('users' => $users));

        $response = new Response($content);
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

    /**
     * Returns a list of users not registered to the Group $group whose username, firstname or lastname
     * matche $search.
     *
     * @param integer search
     * @param integer $group
     * @param integer $offset
     *
     * @return Response
     */
    public function searchGrouplessUsersAction($groupId, $search, $offset)
    {
        $em = $this->getDoctrine()->getEntityManager();
        $paginatorUsers = $em->getRepository('Claroline\CoreBundle\Entity\User')->searchUnregisteredUsersOfGroup($groupId, $search, $offset, self::USER_PER_PAGE);
        $users = $this->paginatorToArray($paginatorUsers);

        $content = $this->renderView(
            "ClarolineCoreBundle:Administration:user_list.json.twig", array('users' => $users));

        $response = new Response($content);
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

    /**
     * Adds multiple user to a group.
     *
     * @param integer $groupId
     *
     * @return Response
     */
    public function multiaddUserstoGroupAction($groupId)
    {
        $em = $this->getDoctrine()->getEntityManager();
        $params = $this->get('request')->query->all();
        $group = $em->getRepository('Claroline\CoreBundle\Entity\Group')->find($groupId);
        $users = array();

        if(isset($params['userId'])){
            foreach ($params['userId'] as $userId) {
                $user = $em->getRepository('Claroline\CoreBundle\Entity\User')->find($userId);
                if($user !== null){
                    $group->addUser($user);
                    $users[] = $user;
                }
            }
        }

        $em->persist($group);
        $em->flush();

        $content = $this->renderView(
            "ClarolineCoreBundle:Administration:user_list.json.twig", array('users' => $users));

        $response = new Response($content);
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

    /**
     * Removes users from a group.
     *
     * @param integer $groupId
     *
     * @return Response
     */
    public function multiDeleteUserFromGroupAction($groupId)
    {
        $params = $this->get('request')->query->all();
        $em = $this->getDoctrine()->getEntityManager();
        $group = $em->getRepository('Claroline\CoreBundle\Entity\Group')->find($groupId);

        if(isset($params['userId'])){
            foreach ($params['userId'] as $userId){
                $user = $em->getRepository('Claroline\CoreBundle\Entity\User')->find($userId);
                $group->removeUser($user);
                $em->persist($group);
            }
        }

        $em->flush();

        return new Response('user removed', 204);
    }

    /**
     * Deletes multiple groups.
     *
     *  @return Response
     */
    public function multiDeleteGroupAction()
    {
        $em = $this->getDoctrine()->getEntityManager();
        $params = $this->get('request')->query->all();

        if(isset($params['id'])){
            foreach ($params['id'] as $groupId) {
                $group = $em->getRepository('Claroline\CoreBundle\Entity\Group')->find($groupId);
                $em->remove($group);
            }
        }

        $em->flush();

        return new Response('groups removed', 204);
    }

    /**
     * Displays an edition form for a group.
     *
     * @param integer $groupId
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function groupSettingsFormAction($groupId)
    {
        $em = $this->getDoctrine()->getEntityManager();
        $group = $em->getRepository('Claroline\CoreBundle\Entity\Group')->find($groupId);
        $form = $this->createForm(new GroupSettingsType(), $group);

        return $this->render(
            'ClarolineCoreBundle:Administration:group_settings_form.html.twig',
            array('group' => $group, 'form_settings' => $form->createView())
        );
    }

    /**
     * Updates the settings of a group and redirects to the group list.
     *
     * @param integer $groupId
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function updateGroupSettingsAction($groupId)
    {
        $request = $this->get('request');
        $em = $this->getDoctrine()->getEntityManager();
        $group = $em->getRepository('Claroline\CoreBundle\Entity\Group')->find($groupId);
        $form = $this->createForm(new GroupSettingsType(), $group);
        $form->bindRequest($request);

        if ($form->isValid()) {
            $group = $form->getData();
            $em->persist($group);
            $em->flush();

            return $this->redirect($this->generateUrl('claro_admin_group_list'));
        }

        return $this->render(
            'ClarolineCoreBundle:Administration:group_settings_form.html.twig',
            array('group' => $group, 'form_settings' => $form->createView())
        );
    }

    /**
     * Displays the platform settings.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function platformSettingsFormAction()
    {
        $platformConfig = $this->get('claroline.config.platform_config_handler')
            ->getPlatformConfig();
        $form = $this->createForm(new PlatformParametersType(), $platformConfig);

        return $this->render(
            'ClarolineCoreBundle:Administration:platform_settings_form.html.twig',
            array('form_settings' => $form->createView())
        );
    }

    /**
     * Updates the platform settings and redirects to the settings form.
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function updatePlatformSettingsAction()
    {
        $request = $this->get('request');
        $configHandler = $this->get('claroline.config.platform_config_handler');
        $form = $this->get('form.factory')->create(new PlatformParametersType());
        $form->bindRequest($request);

        if ($form->isValid()) {
            $configHandler->setParameter('allow_self_registration', $form['selfRegistration']->getData());
            $configHandler->setParameter('locale_language', $form['localLanguage']->getData());
            $configHandler->setParameter('theme', $form['theme']->getData());
        }

        //this form can't be invalid
        return $this->redirect($this->generateUrl('claro_admin_platform_settings_form'));
    }

    /**
     * Display the plugin list
     *
     * @return Response
     */
    public function pluginListAction()
    {
        $em = $this->get('doctrine.orm.entity_manager');
        $plugins = $em->getRepository('ClarolineCoreBundle:Plugin')->findAll();

        return $this->render('ClarolineCoreBundle:Administration:plugins.html.twig',
            array('plugins' => $plugins));
    }

    /**
     * Redirects to the plugin mangagement page.
     *
     * @param strung $domain
     * @return Response
     * @throws \Exception
     */
    public function pluginParametersAction($domain)
    {
        $event = new PluginOptionsEvent();
        $eventName = strtolower("plugin_options_{$domain}");
        $this->get('event_dispatcher')->dispatch($eventName, $event);

        if (!$event->getResponse() instanceof Response) {
            throw new \Exception(
                "Custom event '{$eventName}' didn't return any Response."
            );
        }

        return $event->getResponse();
    }

    /**
     *  Display the list of widget options for the administrator
     *
     * @return Response
     */
    public function widgetListAction()
    {
        $em = $this->get('doctrine.orm.entity_manager');
        $configs = $em->getRepository('ClarolineCoreBundle:Widget\DisplayConfig')->findBy(array('parent' => null));

        return $this->render('ClarolineCoreBundle:Administration:widgets.html.twig',
            array('configs' => $configs));
    }

    /**
     * Set true|false to the widget displayConfig isLockedByAdmin option
     *
     * @param integer $displayConfigId
     */
    public function invertLockWidgetAction($displayConfigId)
    {
        $em = $this->get('doctrine.orm.entity_manager');
        $config = $em->getRepository('ClarolineCoreBundle:Widget\DisplayConfig')->find($displayConfigId);
        $config->invertLock();
        $em->persist($config);
        $em->flush();

        return new Response('success', 204);
    }

    public function configureWidgetAction($widgetId)
    {
        $em = $this->get('doctrine.orm.entity_manager');
        $widget = $em->getRepository('ClarolineCoreBundle:Widget\Widget')->find($widgetId);
        $event = new ConfigureWidgetEvent(null, true);
        $eventName = strtolower("widget_{$widget->getName()}_configuration");
        $this->get('event_dispatcher')->dispatch($eventName, $event);

        if ($event->getContent() !== '') {
            return $this->render('ClarolineCoreBundle:Administration:widget_configuration.html.twig', array('content' => $event->getContent()));
        } else {
            throw new \Exception("event $eventName didn't return any Response");
        }
    }

     /**
     *  Set true|false to the widget displayConfig isVisible option
     *
     * @param integer $displayConfigId
     */
    public function invertVisibleWidgetAction($displayConfigId)
    {
        $em = $this->get('doctrine.orm.entity_manager');
        $config = $em->getRepository('ClarolineCoreBundle:Widget\DisplayConfig')->find($displayConfigId);
        $config->invertVisible();
        $em->persist($config);
        $em->flush();

        return new Response('success', 204);
    }

    private function paginatorToArray($paginator)
    {
        $items = array();

        foreach($paginator as $item){
            $items[] = $item;
        }

        return $items;
    }
}