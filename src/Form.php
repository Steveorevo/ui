<?php

// vim:ts=4:sw=4:et:fdm=marker:fdl=0

namespace atk4\ui;

/**
 * Implements a form.
 */
class Form extends View //implements \ArrayAccess - temporarily so that our build script dont' complain
{
    use \atk4\core\HookTrait;

    // {{{ Properties

    public $ui = 'form';
    public $defaultTemplate = 'form.html';

    /**
     * A current layout of a form, needed if you call $form->addField().
     *
     * @var \atk4\ui\FormLayout\Generic
     */
    public $layout = null;

    /**
     * List of fields currently registered with this form.
     *
     * @var array Array of FormField objects
     */
    public $fields = [];

    public $content = false;

    /**
     * Will point to the Save button. If you don't want to have save, destroy
     * it. Initialized by setLayout().
     *
     * @var Button
     */
    public $buttonSave;

    /**
     * When form is submitted successfully, this template is used by method
     * success() to replace form contents.
     *
     * WARNING: may be removed in the future as we refactor into using Message class
     *
     * @var string
     */
    public $successTemplate = 'form-success.html';

    /**
     * Collection of field's conditions for displaying a target field on the form.
     *
     * Specifying a condition for showing a target field required the name of the target field
     * and the rules to show that target field. Each rule contains a source field's name and a condition for the
     * source field. When each rule is true, then the target field is show on the form.
     *
     *  Combine multiple rules for showing a field.
     *   ex: ['target' => ['source1' => 'notEmpty', 'source2' => 'notEmpty']]
     *   Show 'target' if 'source1' is not empty AND 'source2' is notEmpty.
     *
     *  Combine multiple condition to the same source field.
     *   ex: ['target' => ['source1' => ['notEmpty','number']]
     *   Show 'target' if 'source1 is notEmpty AND is a number.
     *
     *  Combine multiple arrays of rules will OR the rules for the target field.
     *  ex: ['target' => [['source1' => ['notEmpty', 'number']], ['source1' => 'isExactly[5]']
     *  Show "target' if 'source1' is not empty AND is a number
     *      OR
     *  Show 'target' if 'source1' is exactly 5.
     *
     * @var array
     */
    public $fieldsDisplayRules = [];

    /**
     * Default selector for jsConditionalForm.
     *
     * @var string
     */
    public $fieldDisplaySelector = '.field';

    /**
     * Use this apiConfig variable to pass API settings to Semantic UI in .api().
     *
     * @var array
     */
    public $apiConfig = [];

    /**
     * Use this formConfig variable to pass settings to Semantic UI in .from().
     *
     * @var array
     */
    public $formConfig = [];

    // }}}

    // {{{ Base Methods

    public function __construct($class = null)
    {
        if ($class) {
            $this->addClass($class);
        }
    }

    public function init()
    {
        parent::init();

        // Initialize layout, so when you call addField / setModel next time, form will know
        // where to add your fields.
        $this->initLayout();
    }

    /**
     * initialize form layout. You can inject custom layout
     * if you 'layout'=>.. to constructor.
     */
    protected function initLayout()
    {
        if ($this->layout === null) {
            $this->layout = 'Generic';
        }

        if (is_string($this->layout) || is_array($this->layout)) {
            $this->layout = $this->factory($this->layout, ['form'=>$this], 'FormLayout');
            $this->layout = $this->add($this->layout);
        } elseif (is_object($this->layout)) {
            $this->layout->form = $this;
            $this->add($this->layout);
        } else {
            throw new Exception(['Unsupported specification of form layout. Can be array, string or object', 'layout' => $this->layout]);
        }

        // Layout needs to have a save button
        $this->buttonSave = $this->layout->addButton(['Save', 'primary']);
        $this->buttonSave->setAttr('tabindex', 0);
        $this->buttonSave->on('click', $this->js()->form('submit'));
        $this->buttonSave->on('keypress', new jsExpression('if (event.keyCode === 13){$([name]).form("submit");}', ['name' => '#'.$this->name]));
    }

    /**
     * Add a View into the form layout.
     * The newly added view or a sub view of the newly added view
     * can later be used to add form field to it.
     *
     * @param string|array|View $view
     * @param bool              $hasDivider
     *
     * @throws Exception
     *
     * @return View
     */
    public function addLayoutView($view, $hasDivider = true)
    {
        if ($this->layout === null) {
            throw new Exception('Layout need to be initialized prior to add View.');
        }

        $v = $this->layout->add($view);
        if ($hasDivider) {
            $this->layout->add(['ui' => 'hidden divider']);
        }

        return $v;
    }

    /**
     * Enable a layout added view to be able to add form field to it.
     *
     * @param View   $view   The layout view where field needs to be added.
     * @param string $layout The layout used to added field in view.
     *
     * @throws Exception
     *
     * @return mixed The form layout where field can be added.
     */
    public function enableAddField($view, $layout = 'FormLayout/Generic')
    {
        return $view->add([$layout, 'form' => $this]);
    }

    /**
     * Setter for field display rules.
     *
     * @param array $rules
     *
     * @return $this
     */
    public function setFieldsDisplayRules($rules = [])
    {
        $this->fieldsDisplayRules = $rules;

        return $this;
    }

    /**
     * Set display rule for a group collection.
     *
     * @param array  $rules
     * @param string $selector
     *
     * @return $this
     */
    public function setGroupDisplayRules($rules = [], $selector = '.atk-form-group')
    {
        $this->fieldsDisplayRules = $rules;
        $this->fieldDisplaySelector = $selector;

        return $this;
    }

    /**
     * Associates form with the model but also specifies which of Model
     * fields should be added automatically.
     *
     * If $actualFields are not specified, then all "editable" fields
     * will be added.
     *
     * @param \atk4\data\Model $model
     * @param array            $fields
     *
     * @return \atk4\data\Model
     */
    public function setModel(\atk4\data\Model $model, $fields = null)
    {
        // Model is set for the form and also for the current layout
        try {
            $model = parent::setModel($model);
            $this->layout->setModel($model, $fields);

            return $model;
        } catch (Exception $e) {
            throw $e->addMoreInfo('model', $model);
        }
    }

    /**
     * Adds callback in submit hook.
     *
     * @param callable $callback
     */
    public function onSubmit($callback)
    {
        $this->addHook('submit', $callback);

        return $this;
    }

    /**
     * Return Field decorator associated with
     * the field.
     *
     * @param string $name Name of the field
     */
    public function getField($name)
    {
        return $this->fields[$name];
    }

    /**
     * Causes form to generate error.
     *
     * @param string $field Field name
     * @param string $str   Error message
     *
     * @return jsChain
     */
    public function error($field, $str)
    {
        return $this->js()->form('add prompt', $field, $str);
    }

    /**
     * Causes form to generate success message.
     *
     * @param string $str        Success message
     * @param string $sub_header Sub-header
     *
     * @return jsChain
     */
    public function success($str = 'Success', $sub_header = null)
    {
        /*
         * below code works, but polutes output with bad id=xx
        $success = new Message([$str, 'id'=>false, 'type'=>'success', 'icon'=>'check']);
        $success->app = $this->app;
        $success->init();
        $success->text->addParagraph($sub_header);
         */
        $success = $this->app->loadTemplate($this->successTemplate);
        $success['header'] = $str;

        if ($sub_header) {
            $success['message'] = $sub_header;
        } else {
            $success->del('p');
        }

        $js = $this->js()
            ->html($success->render());

        return $js;
    }

    // }}}

    // {{{ Layout Manipulation

    /**
     * Add field into current layout. If no layout, create one. If no model, create blank one.
     *
     * @param string|null              $name
     * @param array|string|object|null $decorator
     * @param array|string|object|null $field
     *
     * @return FormField\Generic
     */
    public function addField($name, $decorator = null, $field = null)
    {
        if (!$this->model) {
            $this->model = new \atk4\ui\misc\ProxyModel();
        }

        return $this->layout->addField($name, $decorator, $field);
    }

    /**
     * Add header into the form, which appears as a separator.
     *
     * @param string $title
     *
     * @return \atk4\ui\FormLayout\Generic
     */
    public function addHeader($title = null)
    {
        return $this->layout->addHeader($title);
    }

    /**
     * Creates a group of fields and returns layout.
     *
     * @param string|array $title
     *
     * @return \atk4\ui\FormLayout\Generic
     */
    public function addGroup($title = null)
    {
        return $this->layout->addGroup($title);
    }

    /**
     * Returns JS Chain that targets INPUT element of a specified field. This method is handy
     * if you wish to set a value to a certain field.
     *
     * @param string $name Name of element
     *
     * @return jsChain
     */
    public function jsInput($name)
    {
        return $this->layout->getElement($name)->js()->find('input');
    }

    /**
     * Returns JS Chain that targets INPUT element of a specified field. This method is handy
     * if you wish to set a value to a certain field.
     *
     * @param string $name Name of element
     *
     * @return jsChain
     */
    public function jsField($name)
    {
        return $this->layout->getElement($name)->js();
    }

    // }}}

    // {{{ Internals

    /**
     * Provided with a Agile Data Model Field, this method have to decide
     * and create instance of a View that will act as a form-field. It takes
     * various input and looks for hints as to which class to use:.
     *
     * 1. The $seed argument is evaluated
     * 2. $f->ui['form'] is evaluated if present
     * 3. $f->type is converted into seed and evaluated
     * 4. lastly, falling back to Line, DropDown (based on $reference and $enum)
     *
     * @param \atk4\data\Field $f    Data model field
     * @param array            $seed Defaults to pass to factory() when decorator is initialized
     *
     * @return FormField\Generic
     */
    public function decoratorFactory(\atk4\data\Field $f, $seed = [])
    {
        if ($f && !$f instanceof \atk4\data\Field) {
            throw new Exception(['Argument 1 for decoratorFactory must be \atk4\data\Field or null', 'f' => $f]);
        }

        $fallback_seed = ['Line'];

        if ($f->type != 'boolean') {
            if ($f->enum) {
                $fallback_seed = ['DropDown', 'values' => array_combine($f->enum, $f->enum)];
            } elseif ($f->values) {
                $fallback_seed = ['DropDown', 'values' => $f->values];
            } elseif (isset($f->reference)) {
                $fallback_seed = ['DropDown', 'model' => $f->reference->refModel()];
            }
        }

        if (isset($f->ui['hint'])) {
            $fallback_seed['hint'] = $f->ui['hint'];
        }

        if (isset($f->ui['placeholder'])) {
            $fallback_seed['placeholder'] = $f->ui['placeholder'];
        }

        $seed = $this->mergeSeeds(
            $seed,
            isset($f->ui['form']) ? $f->ui['form'] : null,
            isset($this->typeToDecorator[$f->type]) ? $this->typeToDecorator[$f->type] : null,
            $fallback_seed
        );

        $defaults = [
            'form'       => $this,
            'field'      => $f,
            'short_name' => $f->short_name,
        ];

        return $this->factory($seed, $defaults, 'FormField');
    }

    /**
     * Provides decorator seeds for most common types.
     *
     * @var array Describes how factory converts type to decorator seed
     */
    protected $typeToDecorator = [
        'boolean'  => 'CheckBox',
        'text'     => 'TextArea',
        'string'   => 'Line',
        'password' => 'Password',
        'datetime' => 'Calendar',
        'date'     => ['Calendar', 'type' => 'date'],
        'time'     => ['Calendar', 'type' => 'time', 'ampm' => false],
        'money'    => 'Money',
    ];

    /**
     * Looks inside the POST of the request and loads it into a current model.
     */
    public function loadPOST()
    {
        $post = $_POST;

        $this->hook('loadPOST', [&$post]);
        $errors = [];

        foreach ($this->fields as $key => $field) {
            try {
                $value = isset($post[$key]) ? $post[$key] : null;

                // save field value only if field was editable in form at all
                if (!$field->readonly && !$field->disabled) {
                    $this->model[$key] = $this->app->ui_persistence->typecastLoadField($field->field, $value);
                }
            } catch (\atk4\core\Exception $e) {
                $errors[$key] = $e->getMessage();
            }
        }

        if ($errors) {
            throw new \atk4\data\ValidationException($errors);
        }
    }

    /**
     * Renders view.
     */
    public function renderView()
    {
        $this->ajaxSubmit();
        if (!empty($this->fieldsDisplayRules)) {
            $this->js(true, new jsConditionalForm($this, $this->fieldsDisplayRules, $this->fieldDisplaySelector));
        }

        return parent::renderView();
    }

    /**
     * Set Semantic-ui Api settings to use with form. A complete list is here:
     * https://semantic-ui.com/behaviors/api.html#/settings.
     *
     * @param array $config
     *
     * @return $this
     */
    public function setApiConfig($config)
    {
        $this->apiConfig = array_merge($this->apiConfig, $config);

        return $this;
    }

    /**
     * Set Semantic-ui From settings to use with form. A complete list is here:
     * https://fomantic-ui.com/behaviors/form.html#/settings.
     *
     * @param array $config
     *
     * @return $this
     */
    public function setFormConfig($config)
    {
        $this->formConfig = array_merge($this->formConfig, $config);

        return $this;
    }

    /**
     * Does ajax submit.
     */
    public function ajaxSubmit()
    {
        $this->_add($cb = new jsCallback(), ['desired_name' => 'submit', 'postTrigger' => true]);

        $this->add(new View(['element' => 'input']))
            ->setAttr('name', $cb->postTrigger)
            ->setAttr('value', 'submit')
            ->setStyle(['display' => 'none']);

        $cb->set(function () {
            $caught = function ($e, $useWindow) {
                $html = '<div class="header"> '.
                        htmlspecialchars(get_class($e)).
                        ' </div> <div class="content"> '.
                        ($e instanceof \atk4\core\Exception ? $e->getHTML() : nl2br(htmlspecialchars($e->getMessage()))).
                        ' </div>';
                $this->app->terminate(json_encode(['success' => false, 'message' => $html, 'useWindow' => $useWindow]));
            };

            try {
                $this->loadPOST();
                ob_start();
                $response = $this->hook('submit');
                $output = ob_get_clean();

                if ($output) {
                    $message = new Message('Direct Output Detected');
                    $message->init();
                    $message->addClass('error');
                    $message->text->set($output);

                    return $message;
                }

                if (!$response) {
                    if (!$this->model instanceof \atk4\ui\misc\ProxyModel) {
                        $this->model->save();

                        return $this->success('Form data has been saved');
                    } else {
                        return new jsExpression('console.log([])', ['Form submission is not handled']);
                    }
                }
            } catch (\atk4\data\ValidationException $val) {
                $response = [];
                foreach ($val->errors as $field => $error) {
                    $response[] = $this->error($field, $error);
                }

                return $response;
            } catch (\Error $e) {
                return $caught($e, false);
            } catch (\Exception $e) {
                return $caught($e, true);
            }

            return $response;
        });

        //var_dump($cb->getURL());
        $this->js(true)
            ->api(array_merge(['url' => $cb->getJSURL(), 'method' => 'POST', 'serializeForm' => true], $this->apiConfig))
            ->form(array_merge(['inline' => true, 'on' => 'blur'], $this->formConfig));

        $this->on('change', 'input', $this->js()->form('remove prompt', new jsExpression('$(this).attr("name")')));
    }

    // }}}
}
