<?php

namespace Drupal\city_map\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;
use Drupal\file\Entity\File;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

class CityMapForm extends FormBase
{

    /**
     * {@inheritdoc}
     */
    public function getFormId()
    {
        return 'city_map_form';
    }

    /**
     * {@inheritdoc}
     */

    public function buildForm(array $form, FormStateInterface $form_state, $srcId = FALSE)
    {
        $form['#theme'] = 'city_map';
        $form['#attached']['library'][] = 'city_map/city-map-library';
        return $form;
    }


    public function submitForm(array &$form, FormStateInterface $form_state) {
        //submit  default handler
    }
}
