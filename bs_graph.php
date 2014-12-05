<?php

/**
 * ВЕРШИНА ГРАФА
 */
class Vertex
{
    public $city_id;
    public $time;
    public $state;

    /**
     * @param int $city_id
     * @param int $time: unix timestamp
     * @param bool|null $state: {1 -> arrive, 0 -> departure, NULL -> transfer}
     */
    public function __construct($city_id, $time, $state) {
        $this->city_id = (int) $city_id;
        $this->time = (int) $time;
        $this->state = (int) $state;
    }

    public function toString() {
        return $this->city_id.'-'.$this->time.'-'.$this->state;
    }
}


/**
 * РЕБРО ГРАФА
 */
class Edge
{
    public $passage_id;
    public $price;
    public $weight;
    public $start_vertex;
    public $end_vertex;

    /**
     * @param int $passage_id
     * @param string $start_vertex_key
     * @param Vertex $end_vertex
     * @param float $price
     * @param float $weight
     */
    public function __construct($passage_id, $start_vertex_key, Vertex $end_vertex, $price, $weight) {
        $this->passage_id = $passage_id;
        $this->start_vertex = $start_vertex_key;
        $this->end_vertex = $end_vertex;
        $this->price = $price;
        $this->weight = $weight;
    }
}


/**
 * ПУНКТ НА МАРШРУТЕ
 */
class Waypoint
{
    public $city_id;
    public $arrival_time;
    public $departure_time;
    public $price; //цена от начала до текущего пункта

    /**
     * @param int $city_id
     * @param int|null $arrival_time
     * @param int|null $departure_time
     * @param float $price
     */
    public function __construct($city_id, $arrival_time, $departure_time, $price) {
        $this->city_id = $city_id;
        $this->arrival_time = $arrival_time;
        $this->departure_time = $departure_time;
        $this->price = $price;
    }
}

/**
 * КЛАССЫ МАРШРУТОВ
 */
class Paths_class
{
    public $prefix = array();
    public $prohibited_verticies = array();
    public $shortest_path = array();
    public $path_length = 0;

    /**
     * @param array $prefix
     * @param array $prohibited_verticies
     * @param array $shortest_path
     */
    public function __construct(array $prefix, array $prohibited_verticies, array $shortest_path) {
        $this->prefix = $prefix;
        $this->prohibited_verticies = $prohibited_verticies;
        $this->shortest_path = $shortest_path;

        foreach($shortest_path as $edge) {
            $this->path_length += $edge->weight;
        }
    }
}


/**
 * ГРАФ МАРШРУТОВ (ОРИЕНТИРОВАННЫЙ)
 */
class Graph
{
    public $graph = array();
    private $passages = array(); //По каждому маршруту - список пунктов, через которые он проезжает
    private $city_from;
    private $city_to;
    private $CI;

    /**
     * Creating graph
     *
     * @param array $graph_data: data exported from DB
     * @param $f: callback function to get weights
     * @param bool $is_direct: search only direct routes
     * @param array $cities
     * @param int $city_from
     * @param int $city_to
     */
    public function __construct(array $graph_data, $f, $is_direct, array $cities, $city_from, $city_to) {
        $this->city_from = $city_from;
        $this->city_to = $city_to;

        $visited_waypoints = array();
        $last_passage_id = NULL;

        //массивы временных меток для всех пунктов, через которые проходят маршруты
        $times_arrival = array();
        $times_departure = array();

        foreach($graph_data as $route) {
            //Если начался новый рейс
            if ($last_passage_id != $route->passage_id) {
                $last_passage_id = $route->passage_id;
                $visited_waypoints = array(); //Обнуляем посещённые пункты в данном рейсе
            }

            //Время отправления из начального пункта
            $start_time = strtotime($route->start_time);

            //Время прибытия в промежуточный пункт
            $waypoint_arrival_time = ($route->arrival_time != NULL) ? $start_time + $route->arrival_time * 60 : NULL;

            //Время отправления из промежуточного пункта
            $waypoint_departure_time = ($route->departure_time != NULL) ? $start_time + $route->departure_time * 60 : NULL;


            //Заполняем массив всех доступных временных меток для городов (прибытие, отправление)
            if ($waypoint_arrival_time != NULL) {
                $times_arrival[$route->city_id][] = array($route->passage_id, $waypoint_arrival_time);
            }

            if ($waypoint_departure_time != NULL) {
                $times_departure[$route->city_id][] = array($route->passage_id, $waypoint_departure_time);
            }


            if (count($visited_waypoints)) {
                $end_vertex = new Vertex($route->city_id, $waypoint_arrival_time, 1);
                $this->graph[$end_vertex->toString()] = array(); //для пересадок

                //Заполняем граф посещёнными пунктами маршрута
                foreach ($visited_waypoints as $visited_waypoint) {
                    $duration = ($waypoint_arrival_time - $visited_waypoint->departure_time) / 60; //время проезда по ребру в минутах
                    $price = $route->km_price * $route->distance - $visited_waypoint->price; //стоимость проезда по данному ребру

                    $start_vertex = new Vertex($visited_waypoint->city_id, $visited_waypoint->departure_time, 0);
                    $start_vertex_key = $start_vertex->toString();

                    $this->graph[$start_vertex_key][] = new Edge(
                        $route->passage_id,
                        $start_vertex_key,
                        $end_vertex,
                        round($price, 2),
                        $f($duration, $price, 0) //вес ребра
                    );
                }
            }

            //Добавляем очередной посещённый пункт маршрута
            $waypoint_price = $route->km_price * $route->distance;
            $this->passages[$last_passage_id][] = $route->city_id;
            $visited_waypoints[] = new Waypoint($route->city_id, $waypoint_arrival_time, $waypoint_departure_time, $waypoint_price);
        }

        if (!$is_direct) {
            //Поиск и добавление в граф пересадочных ребер
            foreach($times_arrival as $city_id => $time_points) {
                if (isset($times_departure[$city_id]) && isset($cities[$city_id])) {
                    /*
                     * Добавляем в граф все возможные пересадки для городов
                     * (city_id, 12:00) - (city_id, 14:00)  => стоянка данного рейса на вокзале 2 часа (passage_id у рёбер одинаковые)
                     * (city_id, 12:00) - (city_id, 14:00)  => подождать на вокзале 2 часа до пересадки на следующий рейс (passage_id у рёбер разные)
                     */

                    foreach($times_arrival[$city_id] as $t1_info) {
                        $t1 = $t1_info[1];
                        $start_vertex = new Vertex($city_id, $t1, 1);
                        $start_vertex_key = $start_vertex->toString();

                        foreach($times_departure[$city_id] as $t2_info) {
                            if ($t1_info[0] != $t2_info[0]) { //Запретить пересадки с текущего рейса на него же
                                $t2 = $t2_info[1];
                                $min_transfer_duration = 60 * 60; //минимальное время для пересадки
                                if ($t2 > $min_transfer_duration + $t1) {
                                    $this->graph[$start_vertex_key][] = new Edge(
                                        NULL,
                                        $start_vertex_key,
                                        new Vertex($city_id, $t2, 0),
                                        (float) 0,
                                        $f(($t2 - $t1) / 60, 0, 1) //вес ребра
                                    );
                                } else if ($t2 + 86400 > $t1 + $min_transfer_duration) {
                                    $this->graph[$start_vertex_key][] = new Edge(
                                        NULL,
                                        $start_vertex_key,
                                        new Vertex($city_id, $t2, 0),
                                        (float) 0,
                                        $f((86400 +  $t2 - $t1) / 60, 0, 1) //вес ребра
                                    );
                                }
                            }
                        }
                    }
                }
            }
        }
    }


    /**
     * ******************************************
     * ********** АЛГОРИТМ ДЕЙКСТРЫ *************
     * ******************************************
     *
     * @param string $start_vertex_key
     * @param array $prohibited_verticies
     * @param array $prohibited_cities
     * @return array|bool
     */
    private function dijkstra($start_vertex_key, array $prohibited_verticies, array $prohibited_cities) {
        $queue = new SplPriorityQueue();
        $queue->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
        $queue->insert($start_vertex_key, 0);

        $weight	= array($start_vertex_key => 0); //веса на вершинах
        $parent	= array(); //родительские вершины

        while(!$queue->isEmpty()) {
            $current = $queue->extract();
            $cur_weight = -$current['priority'];
            $cur_key = $current['data'];

            if ($cur_weight != $weight[$cur_key]) {
                continue;
            }

            //Если конечная вершина, то выходим из цикла (признак конечной вершины - отсутствие времени в ключе вершины)
            if (!isset($this->graph[$cur_key])) {
                return $parent;
            }

            foreach($this->graph[$cur_key] as $edge) {
                if(isset($prohibited_cities[$edge->end_vertex->city_id])) {
                    continue;
                }

                $end_vertex_key = $edge->end_vertex->toString();
                $new_vertex_weight = $cur_weight + $edge->weight;

                if (!isset($prohibited_verticies[$cur_key.'%'.$end_vertex_key]) &&
                    (!isset($weight[$end_vertex_key]) || $new_vertex_weight < $weight[$end_vertex_key])) {
                    //обновили вес у вершины (он лучше)
                    $weight[$end_vertex_key] = $new_vertex_weight;
                    $queue->insert($end_vertex_key, -$new_vertex_weight);
                    $parent[$end_vertex_key] = array($cur_key, $edge);
                }
            }
        }

        return FALSE; //Если в пути не найдено конечной вершины => путь не найден
    }


    /**
     * ***************************************************
     * ********** ПОИСК КРАТЧАЙШЕГО МАРШРУТА *************
     * ***************************************************
     *
     * @param string $start_vertex_key
     * @param array $prohibited_verticies
     * @param array $prohibited_cities
     * @return array
     */
    public function shortest_path($start_vertex_key, array $prohibited_verticies, array $prohibited_cities) {
        $end_vertex_key = $this->city_to.'-'.NULL.'-'.NULL;
        $parents = $this->dijkstra($start_vertex_key, $prohibited_verticies, $prohibited_cities);
        $path = array();

        if (!empty($parents)) {
            while($end_vertex_key != $start_vertex_key) {
                $path[] = $parents[$end_vertex_key][1];
                $end_vertex_key = $parents[$end_vertex_key][0]; //переход назад (пока не дойдём до стартовой вершины)
            }

            $path = array_reverse($path);
        }

        //Принудительное обнаружение и удаление маршрутов с циклами.
        $cities = array();
        foreach($path as $edge) {
            if ($edge->start_vertex == 'start' || !is_null($edge->passage_id)) {
                $city_id = $edge->end_vertex->city_id;
                if (!isset($cities[$city_id])) {
                    $cities[$city_id] = ''; //запоминаем, что маршрут уже проходит через данный город
                } else {
                    /* TODO: дейкстра может проходить во время поиска кратчайшего пути через один город несколько раз
                     * И если этого города нет в $prohibited_cities, то в маршруте возникает цикл, который обнаруживается
                     * вот так, постфактум, и принудительно удаляется. Возможно, если вместо дейкстры использовать алгоритм A*,
                     * то можно будет учитывать во время поиска, прошли ли мы через один город дважды, и этот костыть можно будет убрать.
                    */
                    $path = array(); //В пути найден дублирующийся город, отбрасываем маршрут
                    break;
                }
            }
        }



        //Собираем все рейсы участвующие в данном маршруте
        $route_passages = array();
        foreach($path as $edge) {
            if (!is_null($edge->passage_id)) {
                list($city_start) = explode('-', $edge->start_vertex);
                $city_end = $edge->end_vertex->city_id;
                $route_passages[$edge->passage_id] = array('start' => (int) $city_start, 'end' => $city_end);
            }
        }

        if (count($route_passages) > 1) {
            foreach($route_passages as $passage_id => $final_cities) {
                //Делаем обрезание массива $this->passages[$passage_id] от $passage_cities['start'] до $passage_cities['end'] не влючительно
                $flag = FALSE; //Флаг разрешения взятия города ( который обязательно должен находится между $passage_cities['start'] и $passage_cities['end'] )
                foreach($this->passages[$passage_id] as $city_id) {
                    if ((!$flag && $city_id == $final_cities['start']) || ($flag && $city_id == $final_cities['end'])) {
                        $flag = !$flag;
                        continue;
                    }

                    if ($flag) {
                        if (!isset($cities[$city_id])) {
                            $cities[$city_id] = ''; //Добавляем город в список, т.к. уже проехали через него
                        } else {
                            $path = array(); //В пути найден дублирующийся промежуточный город, отбрасываем маршрут
                            break;
                        }
                    }
                }
            }
        }

        return $path;
    }


    /**
     * **************************************
     * ********** АЛГОРИТМ ЙЕНА *************
     * **************************************
     *
     * @param $routes_limit: Maximum of routes to find
     * @return array
     */
    public function yens_algorithm($routes_limit) {
        $routes = array(); //Найденные k-кратчайших маршрутов
        $shortest_path = $this->shortest_path('start', array(), array());

        if (!empty($shortest_path)) {
            $baseClass = new Paths_class(array(), array(), $shortest_path); //1-й кратчайший путь
            $classes = array($baseClass); //кандидаты в k-кратчайший путь

            //Поиск заданного количества кратчайших путей
            while(!empty($classes) && count($routes) < $routes_limit) {
                $current = NULL; //k-й кратчайший путь
                $current_key = NULL; //ключ кратчайшего пути, по которому будем его удалять из списка кандидатов

                //Выбор лучшего из всех кандидатов к k-кратчайший путь
                foreach($classes as $key => $candidate_class) {
                    if (is_null($current) || $candidate_class->path_length < $current->path_length) {
                        //Найден кандидат лучше предыдущего, обновляем данные.
                        $current = $candidate_class;
                        $current_key = $key;
                    }
                }

                unset($classes[$current_key]); //Удаляем кратчайший путь из списка кандидатов
                $routes[] = $current->shortest_path; //Добавляем путь в список кратчайших

                $prefix = $current->prefix;

                $prohibited_cities = array('start' => '');
                foreach($prefix as $p_edge) {
                    $prohibited_cities[$p_edge->end_vertex->city_id] = ''; //запрещаем все города корневого пути (через них уже не будем проходить)
                }

                $i = count($prefix);
                $shortest_length = count($current->shortest_path); //Количество ребер последнего кратчайшего пути

                while($i < $shortest_length) {
                    $cur_edge = $current->shortest_path[$i];

                    //сворачиваем с пути и ищем новый кратчайший путь
                    $start_vertex_key = $cur_edge->start_vertex;
                    $prohibited_verticies = ($i == count($prefix)) ? $current->prohibited_verticies : array();
                    $prohibited_verticies[$start_vertex_key.'%'.$cur_edge->end_vertex->toString()] = ''; //запрещаем ребро

                    $route = $this->shortest_path($start_vertex_key, $prohibited_verticies, $prohibited_cities); //DIJKSTRA RUN

                    if (!empty($route)) {
                        //Найден новый кратчайший путь, добавляем его в список кандидатов.
                        $route = array_merge($prefix, $route);
                        $classes[] = new Paths_class($prefix, $prohibited_verticies, $route);
                    }

                    $prefix[] = $cur_edge; //увеличиваем префикс
                    $prohibited_cities[$cur_edge->end_vertex->city_id] = ''; //запрещаем город, через который только что проехали
                    ++$i;
                }
            }
        }

        return $routes;
    }


    /**
     * Добавление в граф начальной и конечной вершин
     *
     * @return bool
     */
    private function add_base_vertices()
    {
        $start_exists = FALSE;
        $finish_exists = FALSE;

        $start_vertices_keys = array_keys($this->graph);
        foreach($start_vertices_keys as $start_vertex_key) {
            list($city_id, $waypoint_arrival_time, $state) = explode('-', $start_vertex_key);

            if ($city_id == $this->city_from && $state == 0) {
                $start_exists = TRUE;
                $this->graph['start'][] = new Edge(
                    NULL,
                    'start',
                    new Vertex($city_id, $waypoint_arrival_time, 0),
                    (float) 0,
                    (float) 0 //вес ребра
                );
            } else if ($city_id == $this->city_to && $state == 1) {
                $finish_exists = TRUE;
                $this->graph[$start_vertex_key][] = new Edge(
                    NULL,
                    $start_vertex_key,
                    new Vertex($this->city_to, NULL, NULL),
                    (float) 0,
                    (float) 0 //вес ребра
                );
            }
        }

        return $start_exists && $finish_exists;
    }


    /**
     * ****************************************************
     * ********** ПОИСК ОПТИМАЛЬНЫХ МАРШРУТОВ *************
     * ****************************************************
     * @param int $routes_limit: Maximum of routes to find
     * @return array|bool
     */
    public function get_routes($routes_limit) {
        //Только если в графе существуют вершины для стартового и конечного городов
        if ($this->add_base_vertices()){
            return $this->yens_algorithm($routes_limit);
        } else {
            return array();
        }
    }
}


class Bs_graph
{
    /**
     * @param int $time_weight Продолжительность заданного отрезка пути
     * @param int $price_weight Стоимость заданного отрезка пути
     * @param int $transfer Признак наличия пересадки
     * @return callable
     */
    private function make_f_by_weights($time_weight, $price_weight, $transfer) {
        $callback =
            function ($time, $price, $transfer_weight) use ($time_weight, $price_weight, $transfer) {
                return $time_weight * $time + $price_weight  * $price + $transfer_weight * $transfer;
            };

        return $callback;
    }

    /**
     * Переформатирование маршрута
     *
     * @param array $routes
     * @param array $cities
     * @return array
     */
    public function human_readable_format(array $routes, array $cities) {
        $f_routes = array();

        foreach($routes as $route) {
            array_pop($route); //удаляем последний (финишный элемент)

            $total_price = (float) 0; //Суммарная цена для всего маршрута
            $route_legs = array(); //Информация по каждой дуге маршрута
            $waypoints = array(); //Путевые точки маршрута (используется при отрисовке построенного маршрута на карте)

            $start_time = reset($route)->end_vertex->time;
            $finish_time = end($route)->end_vertex->time;
            $duration = new DateTime('00:00'); //Общая протяженность маршрута по времени

            $diff_routes = array(); //id всех маршрутов использующихся в данном рейсе
            $total_edges = count($route);

            for($i=0; $i < $total_edges; ++$i) {
                //подсчёт пересадок
                $passage_id = $route[$i]->passage_id;
                if (!is_null($passage_id) && !isset($diff_routes[$passage_id])) {
                    $diff_routes[$passage_id] = '';
                }

                //заполняем список промежуточных точек маршрута
                if ($route[$i]->start_vertex == 'start' || !is_null($passage_id)) {
                    $city_id = $route[$i]->end_vertex->city_id;
                    $waypoints[] = array('id' => $city_id, 'lat' => $cities[$city_id]->lat, 'lng' => $cities[$city_id]->lng);
                }

                //вычисление суммарной цены для всего маршрута
                $total_price += $route[$i]->price;

                if ($i < $total_edges - 1) {
                    $arrival_time = new DateTime();
                    $arrival_time->setTimestamp($route[$i+1]->end_vertex->time);

                    $departure_time = new DateTime();
                    $departure_time->setTimestamp($route[$i]->end_vertex->time);

                    $leg_duration = $departure_time->diff($arrival_time);
                    $duration->add($leg_duration);

                    //детали маршрута
                    $leg = new stdClass;
                    $leg->city_from = $route[$i]->end_vertex->city_id;
                    $leg->city_to = $route[$i+1]->end_vertex->city_id;
                    $leg->passage_id = $route[$i+1]->passage_id;
                    $leg->duration = $leg_duration->format('%H:%I');
                    $leg->arrive_time = $arrival_time->format('H:i');
                    $leg->departure_time = $departure_time->format('H:i');

                    $route_legs[] = $leg;
                }
            }

            $formatted_route = new stdClass;
            $formatted_route->waypoints = $waypoints;
            $formatted_route->start_time = date('H:i', $start_time);
            $formatted_route->finish_time = date('H:i', $finish_time);
            $formatted_route->duration = $duration->format('H:i');
            $formatted_route->total_price = $total_price;
            $formatted_route->transfers = count($diff_routes) - 1;
            $formatted_route->details = $route_legs;

            $f_routes[] = $formatted_route;
        }

        return $f_routes;
    }

    /**
     * @param bool $is_direct
     * @param int $city_from
     * @param int $city_to
     * @param array $graph_data
     * @param array $cities
     * @return array|null
     */
    public function search_schedule($is_direct, $city_from, $city_to, array $graph_data, array $cities)
    {
        if (isset($cities[$city_from]) && isset($cities[$city_to])) {
            $f = $this->make_f_by_weights(1, 10, 50); //Задание весов
            $graph = new Graph($graph_data, $f, $is_direct, $cities, $city_from, $city_to); //Создание графа маршрутов
            $routes = $graph->get_routes(40); //Поиск первых N оптимальных маршрутов в графе

            return $this->human_readable_format($routes, $cities);
        }

        return NULL;
    }
}