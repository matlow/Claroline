<?php

namespace Claroline\CoreBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilder;

class DirectoryType extends AbstractType
{

    public function buildForm(FormBuilder $builder, array $options)
    {
        $builder->add('name');
        $builder->add('shareType', 'choice', array(
            'choices' => array(true => ' '),
            'multiple' => true,
            'expanded' => true,
            'label' => 'sharable'
        ));
    }

    public function getName()
    {
        return 'directory_form';
    }

}
